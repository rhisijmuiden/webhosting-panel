<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An extra FTP/SFTP login for one website (docs/ftp-accounts.md). It works as
 * the website's own Linux user, locked in one folder, optionally read-only.
 */
class FtpAccount extends Model
{
    protected $fillable = ['website_id', 'login', 'password_hash', 'folder', 'read_only', 'enabled', 'description', 'password_changed_at'];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return ['read_only' => 'boolean', 'enabled' => 'boolean', 'password_changed_at' => 'datetime'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** The part before the @. */
    public function name(): string
    {
        return strstr($this->login, '@', true) ?: $this->login;
    }

    /** The folder as the customer sees it: "/" for the whole website, "/public/uploads" otherwise. */
    public function folderLabel(): string
    {
        return '/'.$this->folder;
    }

    /** Whether ProFTPD lets this account in right now. */
    public function isActive(): bool
    {
        return $this->enabled && $this->website?->status === 'active';
    }
}
