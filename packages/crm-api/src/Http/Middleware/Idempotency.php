<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Rhis\CrmApi\Support\ApiError;
use Symfony\Component\HttpFoundation\Response;

/** A POST with the same Idempotency-Key within 24 hours returns the first response again. */
final class Idempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if (! $request->isMethod('POST') || ! is_string($key) || $key === '') {
            return $next($request);
        }
        if (strlen($key) > 255) {
            return ApiError::response(422, 'validation_failed', 'Idempotency-Key is too long');
        }
        $token = $request->attributes->get('crm_token');
        $fingerprint = hash('sha256', $request->path()."\n".$request->getContent());

        DB::table('crm_idempotency_keys')->where('created_at', '<', now()->subDay())->delete();
        $hit = DB::table('crm_idempotency_keys')->where('token_id', $token->id)->where('key', $key)->first();
        if ($hit) {
            if ($hit->fingerprint !== $fingerprint) {
                return ApiError::response(409, 'conflict', 'This Idempotency-Key was used for a different request');
            }

            return (new JsonResponse(json_decode($hit->body, true), $hit->status))->header('Idempotent-Replayed', 'true');
        }

        $response = $next($request);
        if ($response->getStatusCode() < 500) {
            DB::table('crm_idempotency_keys')->insertOrIgnore([
                'token_id' => $token->id, 'key' => $key, 'fingerprint' => $fingerprint,
                'status' => $response->getStatusCode(), 'body' => $response->getContent(), 'created_at' => now(),
            ]);
        }

        return $response;
    }
}
