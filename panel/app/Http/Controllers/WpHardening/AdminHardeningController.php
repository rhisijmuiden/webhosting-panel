<?php

namespace App\Http\Controllers\WpHardening;

use Illuminate\Http\Request;
use Rhis\WordPress\Models\WordPressInstall;

/** Admins: every WordPress install. */
final class AdminHardeningController extends HardeningController
{
    protected string $area = 'admin';

    protected function authorizeInstall(Request $request, WordPressInstall $install): void
    {
        abort_unless($install->website !== null, 404);
    }
}
