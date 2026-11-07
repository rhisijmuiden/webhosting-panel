<?php

declare(strict_types=1);

namespace Rhis\WordPress\Mail\Http;

use Illuminate\Http\Request;
use Rhis\WordPress\Models\WordPressInstall;

final class AdminWpMailController extends WpMailController
{
    protected string $area = 'admin';

    protected function authorizeInstall(Request $request, WordPressInstall $install): void {}
}
