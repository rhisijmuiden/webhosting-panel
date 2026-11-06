<?php

declare(strict_types=1);

namespace Rhis\Backups\Models;

use App\Models\Customer;
use App\Models\Website;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Rhis\Backups\OneDrive;

/** A backup location a customer set up: their own FTP, FTPS or SFTP/SCP server, or their OneDrive. */
final class ClientBackupTarget extends Model
{
    public const TYPES = ['sftp', 'scp', 'ftps', 'ftp', 'onedrive'];

    public const PARTS = ['files', 'databases', 'mail'];

    public const SCHEDULES = ['off', 'daily', 'weekly'];

    protected $fillable = [
        'customer_id', 'name', 'type', 'host', 'port', 'username', 'password', 'ssh_key', 'host_key', 'tls', 'path',
        'allow_plain_ftp', 'schedule', 'schedule_time', 'full_day', 'parts', 'website_ids', 'keep_full', 'tested_at', 'last_error',
        'onedrive_token', 'drive_id', 'drive_type', 'account',
    ];

    protected $hidden = ['password', 'ssh_key', 'onedrive_token'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted', 'ssh_key' => 'encrypted', 'onedrive_token' => 'encrypted', 'allow_plain_ftp' => 'boolean',
            'parts' => 'array', 'website_ids' => 'array', 'tested_at' => 'datetime', 'port' => 'integer', 'keep_full' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(ClientBackup::class);
    }

    /** What whp-agent needs to reach the location (secrets included: only for agent calls). */
    public function agentTarget(): array
    {
        if ($this->type === 'onedrive') {
            return array_filter([
                'type' => 'onedrive', 'id' => $this->id, 'token' => $this->onedrive_token, 'drive_id' => $this->drive_id,
                'drive_type' => $this->drive_type, 'path' => $this->path,
            ] + app(OneDrive::class)->agentCredentials(), fn ($v) => $v !== null && $v !== '');
        }

        return array_filter([
            'type' => $this->type, 'host' => $this->host, 'port' => $this->port, 'user' => $this->username,
            'password' => $this->password, 'ssh_key' => $this->ssh_key, 'host_key' => $this->host_key,
            'tls' => $this->tls, 'path' => $this->path, 'allow_plain_ftp' => $this->allow_plain_ftp,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function parts(): array
    {
        return array_values(array_intersect(self::PARTS, $this->parts ?: self::PARTS));
    }

    /** The websites this location backs up on its schedule. */
    public function websites(): Collection
    {
        $query = Website::where('customer_id', $this->customer_id)->orderBy('domain');
        if (is_array($this->website_ids) && $this->website_ids !== []) {
            $query->whereIn('id', $this->website_ids);
        }

        return $query->get();
    }

    public function location(): string
    {
        if ($this->type === 'onedrive') {
            return 'OneDrive'.($this->account ? ' ('.$this->account.')' : '').($this->path !== '' ? ' /'.$this->path : '');
        }
        $port = $this->port ? ':'.$this->port : '';

        return "{$this->username}@{$this->host}{$port}".($this->path !== '' ? '/'.$this->path : '');
    }
}
