<?php

namespace App\Http\Controllers\WpHardening;

use Illuminate\Http\Request;
use Rhis\WordPress\Models\WordPressInstall;

/** Customers: their own WordPress installs, when the admin allows it. */
final class ClientHardeningController extends HardeningController
{
    protected string $area = 'client';

    protected function authorizeInstall(Request $request, WordPressInstall $install): void
    {
        abort_unless(config('wordpress.customer_toolkit') && config('wp-hardening.customer_access'), 404);
        abort_unless($install->website && $install->website->customer_id === $request->user()->customer_id, 404);
    }
}
