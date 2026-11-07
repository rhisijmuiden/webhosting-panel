<?php

declare(strict_types=1);

namespace Rhis\WordPress\DbCleanup;

use Illuminate\Http\Request;
use Rhis\WordPress\Models\WordPressInstall;

final class ClientDbCleanupController extends DbCleanupController
{
    protected string $area = 'client';

    protected function authorizeInstall(Request $request, WordPressInstall $install): void
    {
        abort_unless(config('wordpress.customer_toolkit'), 404);
        abort_unless($install->website && $install->website->customer_id === $request->user()->customer_id, 404);
    }
}
