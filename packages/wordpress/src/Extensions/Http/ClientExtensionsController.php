<?php

declare(strict_types=1);

namespace Rhis\WordPress\Extensions\Http;

use Illuminate\Http\Request;
use Rhis\WordPress\Models\WordPressInstall;

final class ClientExtensionsController extends ExtensionsController
{
    protected string $area = 'client';

    protected function authorizeInstall(Request $request, WordPressInstall $install): void
    {
        abort_unless(config('wordpress.customer_toolkit') && config('wordpress-extensions.customer_access'), 404);
        abort_unless($install->website && $install->website->customer_id === $request->user()->customer_id, 404);
    }
}
