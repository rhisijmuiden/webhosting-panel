<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** One-time login links ("Open hosting panel" in the CRM, welcome mail). */
final class LoginLinks
{
    public function create(User $user, int $minutes, ?string $redirect = null): array
    {
        $token = Str::random(64);
        $expires = now()->addMinutes($minutes);
        Cache::put('crm-login:'.hash('sha256', $token), ['user' => $user->id, 'redirect' => $redirect], $expires);

        return ['url' => route('crm.login', $token), 'expires_at' => $expires->toIso8601String()];
    }

    /** Returns [user, redirect] once; the link cannot be used again. */
    public function consume(string $token): ?array
    {
        $data = Cache::pull('crm-login:'.hash('sha256', $token));
        $user = $data ? User::find($data['user']) : null;

        return $user && $user->is_active !== false ? [$user, $data['redirect']] : null;
    }
}
