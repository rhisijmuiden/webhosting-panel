<?php

declare(strict_types=1);

namespace Rhis\WpIntegrity\Http;

use Illuminate\Http\Request;
use Rhis\WordPress\Models\WordPressInstall;

final class AdminIntegrityController extends IntegrityController
{
    protected string $area = 'admin';

    protected function authorizeInstall(Request $request, WordPressInstall $install): void
    {
        abort_unless($install->website, 404);
    }

    protected function customerId(Request $request): ?int
    {
        return null;
    }
}
