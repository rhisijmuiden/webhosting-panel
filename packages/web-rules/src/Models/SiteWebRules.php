<?php

declare(strict_types=1);

namespace Rhis\WebRules\Models;

use App\Models\Website;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** All redirects and access rules of one website (one row per website). */
class SiteWebRules extends Model
{
    protected $table = 'site_web_rules';

    protected $fillable = [
        'website_id', 'redirects', 'folders', 'error_pages', 'force_https', 'hsts_enabled', 'hsts_max_age',
        'hsts_subdomains', 'hsts_preload', 'access_mode', 'access_networks', 'countries_mode', 'countries', 'applied_at',
    ];

    protected $attributes = [
        'force_https' => true,
        'hsts_enabled' => true,
        'hsts_max_age' => 31536000,
        'hsts_subdomains' => false,
        'hsts_preload' => false,
        'access_mode' => 'off',
        'countries_mode' => 'off',
    ];

    protected function casts(): array
    {
        return [
            'redirects' => 'array',
            'folders' => 'array',
            'error_pages' => 'array',
            'access_networks' => 'array',
            'countries' => 'array',
            'force_https' => 'boolean',
            'hsts_enabled' => 'boolean',
            'hsts_subdomains' => 'boolean',
            'hsts_preload' => 'boolean',
            'hsts_max_age' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** @return list<array> */
    public function redirectList(): array
    {
        return array_values($this->redirects ?? []);
    }

    /** @return list<array> */
    public function folderList(): array
    {
        return array_values($this->folders ?? []);
    }

    /** What webrules.apply gets: everything for this website, password hashes included. */
    public function agentParams(): array
    {
        return [
            'domain' => $this->website->domain,
            'redirects' => array_map(fn ($r) => [
                'from' => $r['from'], 'to' => $r['to'], 'code' => (int) $r['code'],
                'prefix' => (bool) ($r['prefix'] ?? false), 'keep_query' => (bool) ($r['keep_query'] ?? true),
            ], $this->redirectList()),
            'protected' => array_map(fn ($f) => [
                'path' => $f['path'], 'realm' => $f['realm'] ?: 'Restricted area',
                'users' => array_map(fn ($u) => ['name' => $u['name'], 'hash' => $u['hash']], array_values($f['users'] ?? [])),
            ], array_values(array_filter($this->folderList(), fn ($f) => ! empty($f['users'])))),
            'error_pages' => (object) ($this->error_pages ?? []),
            'force_https' => $this->force_https,
            'hsts' => [
                'enabled' => $this->hsts_enabled, 'max_age' => $this->hsts_max_age,
                'subdomains' => $this->hsts_subdomains, 'preload' => $this->hsts_preload,
            ],
            'access' => [
                'mode' => $this->access_mode,
                'networks' => array_values(array_map(fn ($n) => $n['address'], $this->access_networks ?? [])),
                'countries_mode' => $this->countries_mode,
                'countries' => array_values($this->countries ?? []),
            ],
        ];
    }

    /** Short summary for the tiles on the domain page. */
    public function summary(): array
    {
        return [
            'redirects' => count($this->redirectList()),
            'folders' => count($this->folderList()),
            'error_pages' => count($this->error_pages ?? []),
            'blocking' => $this->access_mode !== 'off' || $this->countries_mode !== 'off',
        ];
    }
}
