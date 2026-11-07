<?php

declare(strict_types=1);

namespace Rhis\WordPress\Extensions\Http;

use Illuminate\Http\Request;
use Rhis\WordPress\Models\WordPressInstall;

final class AdminExtensionsController extends ExtensionsController
{
    protected string $area = 'admin';

    protected function authorizeInstall(Request $request, WordPressInstall $install): void {}
}
