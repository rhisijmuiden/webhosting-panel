<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

class ApiToken extends Model
{
    protected $table = 'crm_api_tokens';

    protected $fillable = ['name', 'token_hash', 'prefix', 'allowed_ips', 'last_used_at', 'last_used_ip', 'revoked_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['allowed_ips' => 'array', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** Create a token. The plain value is returned once and only its hash is stored. */
    public static function issue(string $name, array $allowedIps = []): array
    {
        $plain = 'whp_'.Str::random(48);
        $token = self::create([
            'name' => $name, 'token_hash' => hash('sha256', $plain), 'prefix' => substr($plain, 0, 10),
            'allowed_ips' => $allowedIps ?: null,
        ]);

        return [$token, $plain];
    }

    public static function findActive(string $plain): ?self
    {
        return self::where('token_hash', hash('sha256', $plain))->whereNull('revoked_at')->first();
    }

    public function allows(string $ip): bool
    {
        if (empty($this->allowed_ips)) {
            return true;
        }
        foreach ($this->allowed_ips as $range) {
            if (IpUtils::checkIp($ip, $range)) {
                return true;
            }
        }

        return false;
    }
}
