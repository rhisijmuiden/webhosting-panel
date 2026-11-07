<?php

declare(strict_types=1);

namespace Rhis\Apps\Models;

use App\Models\Customer;
use App\Models\Website;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An app (WordPress, Joomla, ...) installed by the app installer in a website. */
final class AppInstallation extends Model
{
    protected $fillable = [
        'website_id', 'customer_id', 'app', 'path', 'status', 'agent_id', 'job_id', 'version',
        'url', 'admin_url', 'admin_user', 'database', 'locale', 'error', 'installed_at',
        'plugin_set_id', 'plugin_set_name', 'plugin_set_report',
    ];

    protected function casts(): array
    {
        return ['installed_at' => 'datetime', 'plugin_set_report' => 'array'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The catalog card of this app (name, monogram, colour). */
    public function meta(): array
    {
        return config('apps.catalog.'.$this->app, ['name' => ucfirst($this->app), 'monogram' => strtoupper($this->app[0] ?? '?'), 'color' => '#555']);
    }

    public function busy(): bool
    {
        return in_array($this->status, ['installing', 'removing'], true);
    }

    /** Where it lives, for people: example.com/blog */
    public function location(): string
    {
        return $this->website?->domain.($this->path !== '' ? '/'.$this->path : '');
    }
}
