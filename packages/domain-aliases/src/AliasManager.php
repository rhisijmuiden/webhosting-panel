<?php

declare(strict_types=1);

namespace Rhis\DomainAliases;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\WebsiteManager;
use Rhis\CrmApi\Models\Order;
use Rhis\CrmApi\Models\Subscription;
use Rhis\CrmApi\Services\Catalog;
use Rhis\CrmApi\Services\Orders;
use Rhis\CrmApi\Services\Subscriptions;
use Rhis\CrmApi\Services\Webhooks;
use Rhis\CrmApi\Support\Resolve;
use Rhis\DnsMail\Models\MailAlias;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Services\DomainProvisioner;
use Rhis\DnsMail\Services\MailPusher;
use Rhis\DomainAliases\Jobs\ProvisionAlias;
use Rhis\DomainAliases\Models\DomainAlias;
use Throwable;

/**
 * Every change to a domain alias goes through here, so the admin panel, the
 * client panel and the CRM API behave the same. The CRM API and DNS/mail
 * packages are optional: without them aliases still work for the web.
 */
final class AliasManager
{
    public const DOMAIN = '/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    /** Catalog kind of the products that turn an alias into a website of its own. */
    public const UPGRADE_KIND = 'alias_upgrade';

    public function __construct(private readonly AgentClient $agent) {}

    public static function normalise(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;

        return rtrim(explode('/', $domain)[0], '.');
    }

    // ---- limits ------------------------------------------------------------

    /** Aliases allowed on this website (null = unlimited). */
    public function limit(Website $website): ?int
    {
        $subscription = class_exists(Subscription::class) ? Subscription::where('website_id', $website->id)->first() : null;
        $override = $subscription?->limit_overrides['domain_aliases'] ?? null;
        if ($override !== null) {
            return (int) $override;
        }
        $raw = $website->package?->getAttribute('crm_limits');
        $planLimits = is_array($raw) ? $raw : (is_string($raw) ? (json_decode($raw, true) ?: []) : []);
        if (isset($planLimits['domain_aliases'])) {
            return (int) $planLimits['domain_aliases'];
        }
        $default = config('domain-aliases.default_limit');

        return $default === null ? null : (int) $default;
    }

    public function used(Website $website): int
    {
        return DomainAlias::where('website_id', $website->id)->count();
    }

    public function canAdd(Website $website): bool
    {
        $limit = $this->limit($website);

        return $limit === null || $this->used($website) < $limit;
    }

    /** Who already serves this host name, or null when it is free. */
    public function owner(string $domain, ?DomainAlias $except = null): ?string
    {
        foreach (array_unique([$domain, 'www.'.$domain, str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain]) as $name) {
            if ($site = Website::where('domain', $name)->first()) {
                return $site->domain;
            }
            $alias = DomainAlias::where('domain', $name)->when($except, fn ($q) => $q->whereKeyNot($except->id))->first();
            if ($alias) {
                return $alias->website?->domain ?? $alias->domain;
            }
        }
        foreach (Website::whereNotNull('aliases')->get(['domain', 'aliases']) as $site) {
            if (in_array($domain, (array) $site->aliases, true)) {
                return $site->domain;
            }
        }

        return null;
    }

    // ---- changes -----------------------------------------------------------

    /**
     * @param  array{domain: string, mode?: string, www?: bool, mail?: bool, ssl?: bool}  $data
     *
     * @throws AliasException
     */
    public function add(Website $website, array $data, string $by = 'panel', bool $enforceLimit = true): DomainAlias
    {
        $domain = self::normalise($data['domain']);
        if (! preg_match(self::DOMAIN, $domain)) {
            throw new AliasException(__('That is not a valid domain name.'));
        }
        if ($domain === $website->domain || $domain === 'www.'.$website->domain) {
            throw new AliasException(__('This is the website itself.'), 'conflict');
        }
        if ($owner = $this->owner($domain)) {
            throw new AliasException(__('This domain is already in use on this server (:site).', ['site' => $owner]), 'conflict');
        }
        if ($enforceLimit && ! $this->canAdd($website)) {
            throw new AliasException(__('Your package allows :count domain aliases for this website.', ['count' => $this->limit($website)]), 'limit');
        }
        if (class_exists(MailDomain::class)) {
            $existing = MailDomain::where('domain', $domain)->first();
            if ($existing && $existing->customer_id !== null && $existing->customer_id !== $website->customer_id) {
                throw new AliasException(__('This domain is already in use on this server (:site).', ['site' => $domain]), 'conflict');
            }
        }
        if ($website->status !== 'active') {
            throw new AliasException(__('The website must be online before you can add an alias.'), 'conflict');
        }

        $mode = in_array($data['mode'] ?? 'serve', DomainAlias::MODES, true) ? ($data['mode'] ?? 'serve') : 'serve';
        $alias = DomainAlias::create([
            'website_id' => $website->id, 'customer_id' => $website->customer_id, 'domain' => $domain,
            'mode' => $mode, 'www' => (bool) ($data['www'] ?? ! str_starts_with($domain, 'www.')),
            'mail' => (bool) ($data['mail'] ?? true), 'ssl_status' => ($data['ssl'] ?? true) ? 'pending' : 'off',
            'status' => 'provisioning', 'created_by' => $by,
        ]);
        ActivityLog::record('domain_alias.added', $domain, ['website' => $website->domain, 'mode' => $mode, 'by' => $by], $website->customer_id);
        ProvisionAlias::dispatch($alias, (bool) ($data['ssl'] ?? true));

        return $alias;
    }

    /** Runs on the queue (ProvisionAlias): server, then mail and DNS. */
    public function provision(DomainAlias $alias, bool $ssl = true): void
    {
        $website = $alias->website;
        try {
            $result = $this->agent->call('site.alias_add', [
                'domain' => $website->domain, 'alias' => $alias->domain, 'mode' => $alias->mode,
                'www' => $alias->www, 'ssl' => $ssl,
            ], 600);
            $alias->fill(['status' => 'active', 'ssl_status' => $result['alias']['ssl'] ?? ($ssl ? 'pending' : 'off'), 'last_error' => null])->save();
        } catch (AgentException $e) {
            $alias->fill(['status' => 'failed', 'last_error' => $e->getMessage()])->save();

            return;
        }
        $this->syncMail($alias);
        $this->webhook('domain_alias.added', $alias);
    }

    /** @throws AliasException */
    public function setMode(DomainAlias $alias, string $mode): void
    {
        $this->assertReady($alias);
        if (! in_array($mode, DomainAlias::MODES, true) || $mode === $alias->mode) {
            return;
        }
        $this->call('site.alias_mode', ['domain' => $alias->website->domain, 'alias' => $alias->domain, 'mode' => $mode], 120);
        $alias->update(['mode' => $mode]);
        ActivityLog::record('domain_alias.mode_changed', $alias->domain, ['mode' => $mode], $alias->customer_id);
    }

    /** @return list<string> warnings */
    public function setMail(DomainAlias $alias, bool $mail): array
    {
        if ($mail === $alias->mail) {
            return [];
        }
        $alias->update(['mail' => $mail]);
        ActivityLog::record('domain_alias.mail_changed', $alias->domain, ['mail' => $mail], $alias->customer_id);

        return $this->syncMail($alias);
    }

    /** @throws AliasException */
    public function retrySsl(DomainAlias $alias): void
    {
        $this->assertReady($alias);
        $result = $this->call('site.alias_ssl', ['domain' => $alias->website->domain, 'alias' => $alias->domain], 300);
        $alias->update(['ssl_status' => $result['alias']['ssl'] ?? $alias->ssl_status]);
    }

    /** @throws AliasException */
    public function remove(DomainAlias $alias, string $by = 'panel'): void
    {
        if ($alias->status === 'provisioning') {
            throw new AliasException(__('The alias is still being set up. Try again in a minute.'), 'conflict');
        }
        try {
            $this->agent->call('site.alias_remove', ['domain' => $alias->website->domain, 'alias' => $alias->domain], 120);
        } catch (AgentException $e) {
            // A failed alias may never have reached the server.
            if ($alias->status !== 'failed') {
                throw new AliasException(__('The change could not be applied. Please try again or contact support.'), 'agent', $e);
            }
        }
        $this->detachMail($alias, keepForwarding: false);
        $this->webhook('domain_alias.removed', $alias);
        ActivityLog::record('domain_alias.removed', $alias->domain, ['website' => $alias->website->domain, 'by' => $by], $alias->customer_id);
        $alias->delete();
    }

    // ---- converting into a website of its own --------------------------------

    /**
     * A website of its own is a new subscription (billed per website), so a customer
     * orders it through the CRM; only the admin converts directly.
     */
    public function canConvertDirectly(DomainAlias $alias): bool
    {
        return false;
    }

    /** Products the CRM offers for converting an alias (catalog kind alias_upgrade). */
    public function upgradeProducts(): array
    {
        return class_exists(Catalog::class) ? app(Catalog::class)->products(self::UPGRADE_KIND) : [];
    }

    /**
     * Turn the alias into a website of its own for the same customer.
     * With $subscription (CRM API: plan_id, external_id, php_version) the
     * website gets a CRM subscription of its own on that plan.
     *
     * @throws AliasException
     */
    public function convert(DomainAlias $alias, string $by = 'panel', ?array $subscription = null): Website
    {
        $this->assertReady($alias);
        $site = $alias->website;
        $customer = $site->customer;
        if (! $customer instanceof Customer) {
            throw new AliasException(__('This website has no customer.'), 'conflict');
        }
        if ($subscription !== null) {
            $plan = Resolve::plan((string) $subscription['plan_id']);
            if (! $plan->is_active) {
                throw new AliasException('This plan is not active', 'conflict');
            }
            if (Subscription::where('external_id', $subscription['external_id'])->exists()) {
                throw new AliasException('A subscription with this external_id exists already', 'conflict');
            }
        }

        // 1. Server: the alias block goes, the certificate stays for the new site.
        $this->call('site.alias_remove', ['domain' => $site->domain, 'alias' => $alias->domain, 'keep_cert' => true], 120);

        // 2. Mail: keep delivering to the main domain's mailboxes through ordinary aliases.
        $this->detachMail($alias, keepForwarding: true);
        $snapshot = $alias->replicate();
        $snapshot->id = $alias->id;
        $orderId = $alias->upgrade_order_id;
        $alias->delete();

        // 3. The new website (the dns-mail package links the existing mail domain to it).
        if ($subscription !== null) {
            [$sub] = app(Subscriptions::class)->create($customer, $plan, [
                'external_id' => $subscription['external_id'], 'primary_domain' => $snapshot->domain,
                'php_version' => $subscription['php_version'] ?? $site->php_version,
            ]);
            $website = $sub->website;
        } else {
            // A new subscription on the same package as the website the alias came from.
            $website = app(WebsiteManager::class)->create($customer, [
                'domain' => $snapshot->domain, 'php_version' => $site->php_version, 'profile' => 'php',
                'ssl' => true, 'www_alias' => $snapshot->www, 'hosting_package_id' => $site->subscription?->hosting_package_id,
            ]);
        }
        ActivityLog::record('domain_alias.converted', $snapshot->domain, ['from' => $site->domain, 'by' => $by, 'order' => $orderId], $customer->id);
        $this->webhook('domain_alias.converted', $snapshot, [
            'website' => ['id' => (string) $website->id, 'domain' => $website->domain],
            'subscription_id' => isset($sub) ? $sub->id : null,
            'order_id' => $orderId,
        ]);

        return $website;
    }

    /**
     * No room in the package: ask the CRM for an upgrade. The CRM gets
     * order.requested; when it sets the order to accepted or completed the
     * alias is converted automatically (see DomainAliasesServiceProvider).
     *
     * @throws AliasException
     */
    public function requestUpgrade(DomainAlias $alias, string $productCode): Order
    {
        $this->assertReady($alias);
        $product = collect($this->upgradeProducts())->firstWhere('code', $productCode);
        if (! $product) {
            throw new AliasException(__('This product is not available.'), 'not_found');
        }
        if (($open = $alias->upgradeOrder()) && in_array($open->status, ['requested', 'awaiting_payment', 'accepted'], true)) {
            return $open;
        }
        // domain = the website (links the order to its subscription); the note names the alias.
        $order = app(Orders::class)->request($alias->website->customer, $productCode, 1, $alias->website->domain,
            "domain_alias:{$alias->domain} Convert domain alias {$alias->domain} of {$alias->website->domain} into its own website");
        $alias->update(['upgrade_order_id' => $order->id]);

        return $order;
    }

    /** Called when the CRM changes an order. */
    public function orderChanged(Order $order): void
    {
        $alias = DomainAlias::where('upgrade_order_id', $order->id)->first();
        if (! $alias) {
            return;
        }
        if (in_array($order->status, ['rejected', 'cancelled'], true)) {
            $alias->update(['upgrade_order_id' => null]);

            return;
        }
        if (in_array($order->status, ['accepted', 'completed'], true) && $alias->status === 'active') {
            try {
                $this->convert($alias, 'crm');
            } catch (Throwable $e) {
                report($e);
                $alias->update(['last_error' => $e->getMessage()]);
            }
        }
    }

    // ---- mail and DNS (dns-mail package) -------------------------------------

    /** Create or update the mail domain of the alias, then push mail + DNS. @return list<string> warnings */
    public function syncMail(DomainAlias $alias): array
    {
        if (! class_exists(MailDomain::class)) {
            return [];
        }
        try {
            $site = $alias->website;
            $main = MailDomain::where('domain', $site->domain)->first();
            $forward = $alias->mail && $main !== null && $main->hasLocalMail();
            $md = MailDomain::firstOrNew(['domain' => $alias->domain]);
            $hasOwn = $md->exists && ($md->mailboxes()->exists() || $md->aliases()->exists());
            $md->fill([
                'customer_id' => $site->customer_id,
                'website_id' => $site->id,
                'alias_of_id' => $forward ? $main->id : null,
                'provider' => $forward || $hasOwn ? 'rhis' : 'external',
                'proxied' => $main?->proxied ?? true,
            ])->save();

            return app(DomainProvisioner::class)->afterChange($md, withDkim: $md->hasLocalMail());
        } catch (Throwable $e) {
            report($e);

            return [__('Mail and DNS were not updated: :error', ['error' => $e->getMessage()])];
        }
    }

    /**
     * The alias stops being an alias. Without $keepForwarding its mail domain
     * goes (unless it has mailboxes of its own); with it, user@alias keeps
     * reaching user@main through ordinary mail aliases (alias -> website).
     */
    private function detachMail(DomainAlias $alias, bool $keepForwarding): void
    {
        if (! class_exists(MailDomain::class)) {
            return;
        }
        try {
            $md = MailDomain::where('domain', $alias->domain)->first();
            if (! $md) {
                return;
            }
            $main = $md->aliasOf;
            if ($keepForwarding && $main) {
                $taken = [...$md->mailboxes()->pluck('local_part')->all(), ...$md->aliases()->pluck('local_part')->all()];
                foreach ($main->mailboxes as $box) {
                    if (! in_array($box->local_part, $taken, true)) {
                        MailAlias::create(['mail_domain_id' => $md->id, 'local_part' => $box->local_part, 'targets' => [$box->local_part.'@'.$main->domain]]);
                    }
                }
                foreach ($main->aliases as $a) {
                    if (! in_array($a->local_part, $taken, true)) {
                        MailAlias::create(['mail_domain_id' => $md->id, 'local_part' => $a->local_part, 'targets' => array_values($a->targets)]);
                    }
                }
            }
            $own = $md->mailboxes()->exists() || $md->aliases()->exists();
            if ($keepForwarding || $own) {
                $md->update(['alias_of_id' => null, 'website_id' => null]);
            } else {
                $md->delete();
            }
            app(MailPusher::class)->push();
        } catch (Throwable $e) {
            report($e);
        }
    }

    // ---- helpers -------------------------------------------------------------

    /** @throws AliasException */
    private function assertReady(DomainAlias $alias): void
    {
        if ($alias->status !== 'active') {
            throw new AliasException(__('The alias is still being set up. Try again in a minute.'), 'conflict');
        }
    }

    /** @throws AliasException */
    private function call(string $action, array $params, int $timeout): array
    {
        try {
            return $this->agent->call($action, $params, $timeout);
        } catch (AgentException $e) {
            throw new AliasException(__('The change could not be applied. Please try again or contact support.'), 'agent', $e);
        }
    }

    private function webhook(string $event, DomainAlias $alias, array $extra = []): void
    {
        if (! class_exists(Webhooks::class)) {
            return;
        }
        try {
            app(Webhooks::class)->dispatch($event, ['domain_alias' => Present::alias($alias)] + $extra);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
