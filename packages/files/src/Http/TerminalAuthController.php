<?php

declare(strict_types=1);

namespace Rhis\Files\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Rhis\Files\FileManager;

/** Nginx auth_request for the web terminal proxy: 204 = allowed, 401/403 = refused. */
final class TerminalAuthController
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        if (! $user || $user->is_active === false) {
            return response('', 401);
        }
        if ($user->hasTwoFactor() && ! $request->session()->get('two_factor_passed')) {
            return response('', 401);
        }

        return response('', FileManager::terminalAllowed($user->id, (string) $request->header('X-Original-URI')) ? 204 : 403);
    }
}
