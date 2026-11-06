<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Rhis\CrmApi\Models\ApiToken;
use Rhis\CrmApi\Support\ApiError;
use Symfony\Component\HttpFoundation\Response;

/** Bearer token with the crm ability, optional IP allowlist, per-token rate limit. */
final class AuthenticateToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $token = is_string($plain) && $plain !== '' ? ApiToken::findActive($plain) : null;
        if (! $token || ! $token->allows((string) $request->ip())) {
            return ApiError::response(401, 'unauthorized', 'Missing, invalid or revoked token, or this address is not allowed');
        }

        $key = 'crm-api:'.$token->id;
        $limit = (int) config('crm-api.rate_limit', 120);
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return ApiError::response(429, 'rate_limited', 'Too many requests')
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);

        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }
        $request->attributes->set('crm_token', $token);

        return $next($request);
    }
}
