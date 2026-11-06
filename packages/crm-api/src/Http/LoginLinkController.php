<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Http;

use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Rhis\CrmApi\Services\LoginLinks;

final class LoginLinkController
{
    public function __invoke(Request $request, string $token, LoginLinks $links): RedirectResponse
    {
        $found = $links->consume($token);
        if (! $found) {
            return redirect()->route('login')->withErrors(['email' => __('This login link has expired or was used already.')]);
        }
        [$user, $redirect] = $found;
        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        ActivityLog::record('crm.login_link_used', $user->email, customerId: $user->customer_id);

        $path = is_string($redirect) && preg_match('#^/(?!/)#', $redirect) ? $redirect : '/';

        return redirect($path);
    }
}
