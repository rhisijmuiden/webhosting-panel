<?php

namespace App\Support;

use App\Models\Website;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Everything the domain page shows about one website, gathered from the core
 * and from the optional feature packages. A package that is not installed (or
 * switched off for customers) simply leaves its part out.
 *
 * The same page serves the client panel and the admin panel. Views ask for
 * routes without their area prefix (has('files.browse'), url('files.browse'))
 * and get the client or the admin twin; a client route without an admin twin
 * is simply not there for admins.
 */
class SiteOverview
{
    public const TABS = ['wordpress', 'tools', 'hosting', 'mail', 'start', 'admin'];

    /** Client routes whose admin twin has another name; null means "no admin twin". */
    private const ADMIN_ROUTES = [
        'backups.index' => 'admin.backups.browse',
        'protection.show' => 'admin.protection.edit',
        'shop.index' => null,
    ];

    public readonly Collection $wordpress;

    public readonly Collection $gitRepos;

    public readonly ?object $mailDomain;

    public readonly ?array $security;

    public readonly ?object $lastBackup;

    public readonly ?string $serverIp;

    public readonly ?string $webmailHost;

    public function __construct(public readonly Website $website, public readonly string $area = 'client')
    {
        $this->wordpress = $this->safe(fn () => $this->has('wordpress.index') && $this->allows('wordpress.customer_toolkit')
            ? \Rhis\WordPress\Models\WordPressInstall::where('website_id', $website->id)->orderBy('path')->get() : null) ?? collect();

        $this->gitRepos = $this->safe(fn () => $this->has('git.index') && $this->allows('git.customer_access')
            ? \Rhis\Git\Models\GitRepository::where('website_id', $website->id)->orderBy('name')->get() : null) ?? collect();

        $this->mailDomain = $this->safe(fn () => $this->has('mail.domains.show')
            ? \Rhis\DnsMail\Models\MailDomain::withCount('mailboxes')
                ->where('customer_id', $website->customer_id)
                ->where(fn ($q) => $q->where('website_id', $website->id)->orWhere('domain', $website->domain))
                ->orderByRaw('domain = ? desc', [$website->domain])->first() : null);

        $this->security = $this->safe(function () use ($website) {
            if (! $this->has('vulns.index')) {
                return null;
            }
            $open = \Rhis\Vulnerabilities\Models\Finding::open()->where('website_id', $website->id);

            return ['total' => (clone $open)->count(), 'malware' => (clone $open)->where('kind', 'malware')->count()];
        });

        $this->lastBackup = $this->safe(fn () => $this->has('backups.index')
            ? \Rhis\Backups\Models\ClientBackup::where('website_id', $website->id)->where('action', 'backup')
                ->where('status', 'succeeded')->latest('finished_at')->first() : null);

        $dnsMail = $this->safe(fn () => class_exists(\Rhis\DnsMail\Services\DnsMailConfig::class) ? app(\Rhis\DnsMail\Services\DnsMailConfig::class) : null);
        $this->serverIp = $this->safe(fn () => $dnsMail?->get('ipv4'));
        $this->webmailHost = $this->safe(fn () => $dnsMail?->get('webmail_host'));
    }

    /** The tabs this website gets, in order. WordPress only when WordPress runs on it. */
    public function tabs(): array
    {
        $tabs = [
            'wordpress' => 'WordPress',
            'tools' => __('Dashboard'),
            'hosting' => __('Hosting & DNS'),
            'mail' => __('Email'),
            'start' => __('Get started'),
            'admin' => __('Administration'),
        ];
        if ($this->wordpress->isEmpty()) {
            unset($tabs['wordpress']);
        }
        if (! $this->isAdmin()) {
            unset($tabs['admin']);
        }

        return $tabs;
    }

    public function tab(?string $requested): string
    {
        $tabs = $this->tabs();

        return isset($tabs[$requested]) ? $requested : array_key_first($tabs);
    }

    public function isAdmin(): bool
    {
        return $this->area === 'admin';
    }

    /** The full route name in this area, or null when the area has no such page. */
    public function routeName(string $route): ?string
    {
        $route = preg_replace('/^(client|admin)\./', '', $route);
        if (! $this->isAdmin()) {
            return 'client.'.$route;
        }

        return array_key_exists($route, self::ADMIN_ROUTES) ? self::ADMIN_ROUTES[$route] : 'admin.'.$route;
    }

    /** Whether this page may link to a route ('files.browse'; a 'client.' prefix is accepted too). */
    public function has(string $route): bool
    {
        $name = $this->routeName($route);
        if ($name === null || ! Route::has($name)) {
            return false;
        }

        return $this->isAdmin() || app(\App\Services\Team\TeamAccess::class)->allowsRoute($name);
    }

    /** URL of a route in this area, or null when it is not there (or not allowed). */
    public function url(string $route, mixed $parameters = []): ?string
    {
        return $this->has($route) ? route($this->routeName($route), $parameters) : null;
    }

    /** A customer-facing feature switch (config key); admins always have the feature. */
    public function allows(string $configKey): bool
    {
        return $this->isAdmin() || (bool) config($configKey);
    }

    public function canUseTerminal(): bool
    {
        return ($this->isAdmin() || (bool) $this->website->package?->allow_ssh) && $this->has('files.terminal');
    }

    public function diskPercent(): ?float
    {
        $quota = $this->website->disk_quota_mb ?: $this->website->package?->disk_mb;

        return $quota ? min(100, $this->website->disk_used_mb / $quota * 100) : null;
    }

    public function diskQuotaMb(): ?int
    {
        return $this->website->disk_quota_mb ?: $this->website->package?->disk_mb;
    }

    /** Link to a tab of this website's mail domain page, or null without one. */
    public function mailLink(string $tab): ?string
    {
        return $this->mailDomain ? $this->url('mail.domains.show', [$this->mailDomain->id, 'tab' => $tab]) : null;
    }

    public function hasLocalMail(): bool
    {
        return $this->mailDomain !== null && $this->safe(fn () => $this->mailDomain->hasLocalMail()) === true;
    }

    /** Colours of the website preview, the same as on the overview page. */
    public function hues(): array
    {
        $hues = [['#2B1E2F', '#C9776A'], ['#1D2B3A', '#9BC5C0'], ['#1E2D50', '#9CC3FF'], ['#2E2A1E', '#E2B66B'], ['#20302A', '#8FCBA9']];

        return $hues[$this->website->id % count($hues)];
    }

    /** Run a lookup in an optional package; a missing table or class just means "not available". */
    private function safe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
