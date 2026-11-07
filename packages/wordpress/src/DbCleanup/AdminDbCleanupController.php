<?php

declare(strict_types=1);

namespace Rhis\WordPress\DbCleanup;

use Illuminate\Http\Request;
use Rhis\WordPress\Models\WordPressInstall;

final class AdminDbCleanupController extends DbCleanupController
{
    protected string $area = 'admin';

    protected function authorizeInstall(Request $request, WordPressInstall $install): void {}
}
