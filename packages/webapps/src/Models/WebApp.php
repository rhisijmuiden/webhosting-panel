<?php

declare(strict_types=1);

namespace Rhis\WebApps\Models;

use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Node.js or Python app of a website: a systemd service on the server
 * (whp-webapp-<uid>.service) behind Nginx on mount_path.
 *
 * @property array<string, string>|null $env
 */
class WebApp extends Model
{
    public const RUNTIMES = ['node' => 'Node.js', 'python' => 'Python'];

    /** Start commands to pick from in the form. */
    public const PRESETS = [
        'node' => [
            'npm start' => 'npm start',
            'node server.js' => 'node server.js',
            'node .output/server/index.mjs' => 'Nuxt',
            'node .next/standalone/server.js' => 'Next.js (standalone)',
        ],
        'python' => [
            'gunicorn --bind 127.0.0.1:$PORT --workers 2 wsgi:application' => 'Gunicorn (Django, Flask)',
            'uvicorn main:app --host 127.0.0.1 --port $PORT' => 'Uvicorn (FastAPI)',
            'python app.py' => 'python app.py',
        ],
    ];

    protected $table = 'web_apps';

    protected $fillable = [
        'website_id', 'uid', 'name', 'runtime', 'runtime_version', 'app_root', 'mount_path', 'strip_prefix',
        'start_command', 'build_command', 'env', 'deps_on_deploy', 'enabled', 'port', 'last_state', 'last_error', 'install_job',
    ];

    protected $attributes = ['app_root' => 'app', 'mount_path' => '/', 'enabled' => true, 'deps_on_deploy' => true, 'strip_prefix' => false];

    protected function casts(): array
    {
        return ['env' => 'encrypted:array', 'enabled' => 'boolean', 'deps_on_deploy' => 'boolean', 'strip_prefix' => 'boolean', 'port' => 'integer'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function scopeForCustomer(Builder $q, int $customerId): Builder
    {
        return $q->whereHas('website', fn ($w) => $w->where('customer_id', $customerId));
    }

    public function runtimeLabel(): string
    {
        return (self::RUNTIMES[$this->runtime] ?? $this->runtime).' '.$this->runtime_version;
    }

    public function url(): string
    {
        $scheme = $this->website?->ssl_status === 'active' ? 'https' : 'http';

        return $scheme.'://'.$this->website?->domain.($this->mount_path === '/' ? '/' : $this->mount_path.'/');
    }

    public static function newUid(): string
    {
        do {
            $uid = bin2hex(random_bytes(5));
        } while (self::where('uid', $uid)->exists());

        return $uid;
    }
}
