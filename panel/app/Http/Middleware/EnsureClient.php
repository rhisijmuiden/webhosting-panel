<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->isAdmin()) {
            return redirect()->route('admin.dashboard');
        }
        abort_unless($user?->customer_id, 403);

        // Team members only reach their own areas and websites; every change is audited.
        return app(TeamGate::class)->handle($request, $next);
    }
}
