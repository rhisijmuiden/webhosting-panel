<?php

declare(strict_types=1);

namespace Rhis\WordPress\Mail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Rhis\WordPress\Models\WordPressInstall;

/** How one WordPress install sends its mail (through this server or not, and as whom). */
class WpMailSetting extends Model
{
    protected $table = 'wp_mail_settings';

    protected $fillable = [
        'wordpress_install_id', 'enabled', 'from_email', 'from_name', 'force_from', 'reason', 'applied_at', 'apply_error',
        'last_test_to', 'last_test_at', 'last_test_ok', 'last_test_error', 'last_test_queue_id',
    ];

    protected $attributes = ['enabled' => false, 'force_from' => true];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'force_from' => 'boolean', 'last_test_ok' => 'boolean',
            'applied_at' => 'datetime', 'last_test_at' => 'datetime',
        ];
    }

    public function install(): BelongsTo
    {
        return $this->belongsTo(WordPressInstall::class, 'wordpress_install_id');
    }

    /** The sender WordPress mail gets: the chosen address, or <local part>@<website domain>. */
    public function sender(): string
    {
        return $this->from_email ?: config('wp-mail.local_part', 'noreply').'@'.$this->install->website->domain;
    }
}
