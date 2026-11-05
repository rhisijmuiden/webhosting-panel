<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'customer_id', 'action', 'subject', 'properties', 'ip'];

    protected function casts(): array
    {
        return ['properties' => 'array'];
    }

    /** English labels; translated through lang/<code>.json. */
    public const LABELS = [
        'auth.login' => 'Logged in',
        'auth.logout' => 'Logged out',
        'profile.updated' => 'Profile updated',
        'profile.password_changed' => 'Password changed',
        'profile.two_factor_enabled' => 'Two-factor authentication turned on',
        'profile.two_factor_disabled' => 'Two-factor authentication turned off',
        'customer.created' => 'Customer created',
        'customer.updated' => 'Customer updated',
        'customer.deleted' => 'Customer deleted',
        'customer.user_created' => 'Login created',
        'customer.user_deleted' => 'Login deleted',
        'package.created' => 'Package created',
        'package.updated' => 'Package updated',
        'package.deleted' => 'Package deleted',
        'website.created' => 'Website created',
        'website.php_changed' => 'PHP version changed',
        'website.cache_changed' => 'Page cache changed',
        'website.ssl_requested' => 'Certificate requested',
        'website.removed' => 'Website removed',
        'service.restarted' => 'Service restarted',
        'security.ban' => 'IP address blocked',
        'security.unban' => 'IP address unblocked',
        'firewall.rule_added' => 'Firewall rule added',
        'firewall.rule_deleted' => 'Firewall rule removed',
        'settings.mail_updated' => 'Mail settings changed',
        'settings.mail_tested' => 'Test mail sent',
        'system.update_started' => 'Update started',
        'system.channel_changed' => 'Release channel changed',
    ];

    public function label(): string
    {
        return __(self::LABELS[$this->action] ?? $this->action);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $action, ?string $subject = null, array $properties = [], ?int $customerId = null): self
    {
        $user = auth()->user();

        return static::create([
            'user_id' => $user?->id,
            'customer_id' => $customerId ?? $user?->customer_id,
            'action' => $action,
            'subject' => $subject,
            'properties' => $properties ?: null,
            'ip' => request()?->ip(),
        ]);
    }
}
