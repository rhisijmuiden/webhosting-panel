<?php

declare(strict_types=1);

namespace Rhis\Migration\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\SiteDatabase;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Rhis\DnsMail\Models\MailAlias;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Models\Mailbox;
use Rhis\DnsMail\Services\MailPusher;
use Rhis\Migration\Jobs\PrepareMigration;
use Rhis\Migration\Models\MigrationItem;
use Rhis\Migration\Models\MigrationSource;
use RuntimeException;
use Throwable;

/**
 * Runs the migration of one domain:
 *  1. prepare(): customer, website (whp-site), mail domain and mailboxes here
 *  2. startTransfer(): the agent copies files, databases, mail and certificate
 *  3. tick() picks up the finished agent job and records the result
 * A sync runs step 2 and 3 again; DnsCutover switches DNS at the end.
 */
final class Migrator
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Queue the selected domains. $choices: domain => [customer => 'new'|'<id>', files, databases, mail, ssl].
     *
     * @return list<MigrationItem>
     */
    public function queue(MigrationSource $source, array $choices): array
    {
        $items = [];
        foreach ($choices as $domain => $c) {
            $d = $source->inventoryDomain((string) $domain);
            if ($d === null) {
                continue;
            }
            $item = MigrationItem::firstOrNew(['migration_source_id' => $source->id, 'domain' => $d['name']]);
            if ($item->exists && $item->status !== 'failed') {
                continue; // Already on its way; use sync to copy again.
            }
            $plan = SitePlan::for($d, (string) config('migration.min_php', '8.3'));
            $item->fill([
                'status' => 'queued',
                'source_data' => $d + ['owner' => $source->owner($d['owner_id'] ?? null), 'server_ips' => $source->inventory['server_ips'] ?? []],
                'options' => [
                    'customer' => (string) ($c['customer'] ?? 'new'),
                    'files' => (bool) ($c['files'] ?? true) && ! empty($d['web']),
                    'databases' => (bool) ($c['databases'] ?? true),
                    'mail' => (bool) ($c['mail'] ?? true),
                    'ssl' => (bool) ($c['ssl'] ?? true),
                    'plan' => $plan,
                ],
                'error' => null,
                'job_kind' => 'initial',
            ]);
            if ($plan['php_note']) {
                $item->addNote($plan['php_note']['text'], $plan['php_note']['params']);
            }
            foreach ($d['notes'] ?? [] as $n) {
                $item->addNote($n);
            }
            $item->save();
            $items[] = $item;
        }
        ActivityLog::record('migration.queued', $source->host, ['domains' => count($items)]);
        $this->tick();

        return $items;
    }

    /** Called every minute and after changes: finish agent jobs, start queued items within the parallel limit. */
    public function tick(): void
    {
        foreach (MigrationItem::whereIn('status', ['copying', 'syncing'])->whereNotNull('agent_job')->get() as $item) {
            $this->poll($item);
        }
        $free = max(1, (int) config('migration.parallel', 2)) - MigrationItem::whereIn('status', MigrationItem::ACTIVE)->count();
        if ($free <= 0) {
            return;
        }
        foreach (MigrationItem::where('status', 'queued')->orderBy('id')->limit($free)->get() as $item) {
            $item->update(['status' => 'preparing']);
            PrepareMigration::dispatch($item);
        }
    }

    /** Step 1 (queue job): everything that has to exist here before data is copied. */
    public function prepare(MigrationItem $item): void
    {
        $d = $item->source_data;
        $opt = $item->options;
        $plan = $opt['plan'];
        try {
            $customer = $item->customer ?? $this->customer($item);
            $item->customer()->associate($customer);

            $website = $item->website ?? Website::where('domain', $item->domain)->first();
            if ($website && $website->customer_id !== $customer->id) {
                throw new RuntimeException(__(':domain already exists here for another customer.', ['domain' => $item->domain]));
            }
            if ($website === null) {
                // Before the website exists: the mail domain keeps DNS untouched until the switch.
                $this->mailDomain($item, $customer);
                $website = Website::create([
                    'customer_id' => $customer->id, 'domain' => $item->domain, 'aliases' => $plan['aliases'],
                    'profile' => $plan['profile'], 'php_version' => $plan['php'],
                    'page_cache' => $plan['profile'] !== 'laravel', 'ssl_status' => 'off', 'status' => 'provisioning',
                    'disk_quota_mb' => $customer->package?->disk_mb,
                ]);
            }
            $item->website()->associate($website);
            $item->save();

            if ($website->status !== 'active') {
                $site = $this->agent->call('site.add', array_filter([
                    'domain' => $website->domain, 'php' => $website->php_version, 'profile' => $website->profile,
                    'aliases' => array_values(array_filter($plan['aliases'], fn ($a) => $a !== $website->domain)),
                    'no_alias' => $plan['aliases'] === [], 'docroot' => $plan['docroot'], 'cache' => $website->page_cache,
                    'ssl' => false, 'quota_mb' => $website->disk_quota_mb,
                ], fn ($v) => $v !== null), 840);
                $website->fillFromAgent($site);
                $website->status = 'active';
                $website->last_error = null;
                $website->save();
            }

            if ($opt['mail'] && ! empty($d['mailboxes']) && class_exists(MailDomain::class)) {
                $this->mailboxes($item, $customer);
            }

            $this->startTransfer($item, 'initial');
        } catch (Throwable $e) {
            report($e);
            $item->update(['status' => 'failed', 'error' => $e->getMessage()]);
            ActivityLog::record('migration.failed', $item->domain, ['error' => Str::limit($e->getMessage(), 300)], $item->customer_id);
        }
    }

    /** Step 2: the agent copies. kind initial|sync (mirror everything again) or late_mail (only new mail, after the switch). */
    public function startTransfer(MigrationItem $item, string $kind): void
    {
        $opt = $item->options;
        $d = $item->source_data;
        $params = $item->source->connection() + ['domain' => $item->domain];

        if ($kind !== 'late_mail') {
            if ($opt['files'] && ! empty($d['web'])) {
                $params['files'] = ['target' => $opt['plan']['target'], 'delete' => true];
            }
            if ($opt['databases']) {
                $params['databases'] = $this->databases($item);
            }
            $params['ssl'] = $kind === 'initial' && (bool) $opt['ssl'];
        }
        if ($opt['mail'] && ! empty($d['mailboxes'])) {
            $params['mail'] = ['mode' => $kind === 'late_mail' ? 'add' : 'mirror', 'mailboxes' => array_column($d['mailboxes'], 'local')];
        }

        $job = $this->agent->call('migrate.transfer', $params, 120);
        $item->update([
            'status' => $kind === 'initial' ? 'copying' : 'syncing',
            'job_kind' => $kind, 'agent_job' => $job['job'] ?? null, 'error' => null,
        ]);
        ActivityLog::record('migration.transfer_started', $item->domain, ['kind' => $kind], $item->customer_id);
    }

    /** Step 3: record a finished agent job. */
    public function poll(MigrationItem $item): void
    {
        try {
            $job = $this->agent->call('job.status', ['job' => $item->agent_job]);
        } catch (AgentException $e) {
            if ($e->errorCode === 'not_found') {
                $item->update(['status' => 'failed', 'error' => __('The copy job was lost (agent restarted). Start it again.')]);
            }

            return;
        }
        if (($job['status'] ?? 'running') === 'running') {
            if (isset($job['started_at']) && $job['started_at'] < now()->subDay()->getTimestamp()) {
                $item->update(['status' => 'failed', 'error' => __('The copy job did not finish within a day.')]);
            }

            return;
        }
        if ($job['status'] !== 'succeeded') {
            $item->update(['status' => $item->job_kind === 'late_mail' ? 'live' : 'failed', 'error' => $job['error']['message'] ?? __('The copy job failed.')]);

            return;
        }

        $result = $job['result'] ?? [];
        DB::transaction(function () use ($item, $result) {
            $secrets = $item->secrets ?? [];
            foreach ($result['databases'] ?? [] as $db) {
                if (! ($db['ok'] ?? false) || ! $item->website_id) {
                    continue;
                }
                SiteDatabase::updateOrCreate(['name' => $db['target']], [
                    'website_id' => $item->website_id, 'origin' => 'migration',
                    'users' => array_column($db['users'] ?? [], 'user'),
                ]);
                foreach ($db['users'] ?? [] as $u) {
                    if (! empty($u['new_password'])) {
                        $secrets['db:'.$u['user']] = $u['new_password'];
                        $item->addNote('Database user :user got a new password (the old one could not be copied). Put it in the website configuration; it is shown on this page.', ['user' => $u['user']]);
                    }
                }
            }
            if (($result['ssl']['installed'] ?? false) === false && in_array($result['ssl']['reason'] ?? '', ['expired', 'key_mismatch', 'name_mismatch'], true)) {
                $item->addNote('The certificate of the old server was not taken over (:reason). Let\'s Encrypt issues one after the DNS switch.', ['reason' => $result['ssl']['reason']]);
            }
            $live = $item->job_kind === 'late_mail';
            $item->fill([
                'status' => $live ? 'live' : 'copied',
                'secrets' => $secrets,
                'result' => $result,
                'error' => ($result['errors'] ?? []) ? implode("\n", $result['errors']) : null,
                $item->job_kind === 'initial' ? 'copied_at' : 'synced_at' => now(),
            ])->save();
        });

        if ($item->website) {
            try {
                $item->website->fillFromAgent($this->agent->call('site.show', ['domain' => $item->domain]));
                $item->website->save();
            } catch (AgentException) {
                // Shown on the next refresh.
            }
        }
        ActivityLog::record('migration.transfer_done', $item->domain, ['kind' => $item->job_kind, 'errors' => count($result['errors'] ?? [])], $item->customer_id);
    }

    /** @return list<array{name: string, target: string, users: list<string>}> */
    private function databases(MigrationItem $item): array
    {
        $out = [];
        foreach ($item->source_data['databases'] ?? [] as $db) {
            if (($db['type'] ?? 'mysql') !== 'mysql' || ($db['server'] ?? 'local') !== 'local') {
                continue;
            }
            $name = (string) $db['name'];
            $taken = SiteDatabase::where('name', $name)->where('website_id', '!=', $item->website_id)->exists();
            if ($taken || in_array(strtolower($name), SiteDatabase::RESERVED, true)) {
                $item->addNote('Database :name was skipped: that name is already in use on this server.', ['name' => $name]);
                $item->save();

                continue;
            }
            $out[] = ['name' => $name, 'target' => $name, 'users' => array_values(array_filter($db['users'] ?? [],
                fn ($u) => is_string($u) && preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $u)))];
        }

        return $out;
    }

    private function customer(MigrationItem $item): Customer
    {
        $choice = $item->options['customer'] ?? 'new';
        if (ctype_digit($choice)) {
            return Customer::findOrFail((int) $choice);
        }
        $owner = $item->source_data['owner'] ?? null;
        $isAdmin = ($owner['kind'] ?? 'admin') === 'admin';
        $email = ! $isAdmin ? strtolower(trim((string) ($owner['email'] ?? ''))) : '';
        if ($email !== '' && ($existing = Customer::where('email', $email)->first())) {
            return $existing;
        }
        $subscription = (string) ($item->source_data['subscription'] ?? $item->domain);
        // Domains of one Plesk subscription end up with the same customer.
        $sibling = MigrationItem::where('migration_source_id', $item->migration_source_id)->whereNotNull('customer_id')
            ->where('id', '!=', $item->id)->get()
            ->first(fn (MigrationItem $i) => ($i->source_data['subscription'] ?? null) === $subscription);
        if ($sibling?->customer) {
            return $sibling->customer;
        }

        return Customer::create([
            'name' => $isAdmin ? $subscription : ($owner['name'] ?? $subscription),
            'company' => $isAdmin ? null : (($owner['company'] ?? '') ?: null),
            'email' => $email ?: 'info@'.$subscription,
            'phone' => $isAdmin ? null : (($owner['phone'] ?? '') ?: null),
            'status' => 'active',
            'notes' => __('Migrated from :host.', ['host' => $item->source->hostname ?: $item->source->host]),
        ]);
    }

    private function mailDomain(MigrationItem $item, Customer $customer): void
    {
        if (! class_exists(MailDomain::class)) {
            return;
        }
        MailDomain::firstOrCreate(['domain' => $item->domain], ['customer_id' => $customer->id, 'dns_managed' => false]);
    }

    private function mailboxes(MigrationItem $item, Customer $customer): void
    {
        $domain = MailDomain::firstOrCreate(['domain' => $item->domain], ['customer_id' => $customer->id, 'dns_managed' => false]);
        $creds = collect($this->agent->call('migrate.credentials', $item->source->connection() + ['domain' => $item->domain], 300)['mailboxes'] ?? [])
            ->keyBy('local');

        foreach ($item->source_data['mailboxes'] as $box) {
            $local = (string) $box['local'];
            if (! preg_match('/^[a-z0-9](?:[a-z0-9._+-]{0,62}[a-z0-9])?$/', $local)) {
                $item->addNote('Mailbox :address was skipped: the name is not allowed here.', ['address' => $local.'@'.$item->domain]);

                continue;
            }
            $c = $creds->get($local, ['scheme' => 'none', 'secret' => '']);
            $hash = SitePlan::mailHash((string) $c['scheme'], (string) $c['secret']);
            if ($hash === null) {
                $hash = Mailbox::hashPassword(Str::password(24));
                $item->addNote('The password of :address could not be read from the old server. Set a new one under Mail.', ['address' => $local.'@'.$item->domain]);
            }
            $existing = $domain->mailboxes()->where('local_part', $local)->first();
            if ($existing) {
                continue; // Keep a password set here in the meantime.
            }
            $domain->mailboxes()->create([
                'local_part' => $local, 'password_hash' => $hash, 'quota_mb' => (int) ($box['quota_mb'] ?? 0), 'enabled' => true,
            ]);
        }
        $boxes = $domain->mailboxes()->pluck('local_part')->all();
        $aliases = [];
        foreach ($item->source_data['mailboxes'] as $box) {
            foreach ($box['aliases'] ?? [] as $a) {
                $aliases[$a] = [$box['local'].'@'.$item->domain];
            }
        }
        foreach ($item->source_data['forwarders'] ?? [] as $f) {
            $aliases[$f['local']] = $f['targets'];
        }
        foreach ($aliases as $local => $targets) {
            if (in_array($local, $boxes, true) || ! preg_match('/^[a-z0-9](?:[a-z0-9._+-]{0,62}[a-z0-9])?$/', (string) $local)) {
                continue;
            }
            MailAlias::firstOrCreate(['mail_domain_id' => $domain->id, 'local_part' => $local], ['targets' => array_values($targets)]);
        }
        $item->save();
        app(MailPusher::class)->push();
    }
}
