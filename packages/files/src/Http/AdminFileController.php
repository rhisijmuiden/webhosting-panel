<?php

declare(strict_types=1);

namespace Rhis\Files\Http;

use App\Models\Website;
use Illuminate\Http\Request;

final class AdminFileController extends FileController
{
    protected string $area = 'admin';

    protected function authorizeSite(Request $request, Website $website): void {}

    protected function websites(Request $request)
    {
        return Website::with('customer')->orderBy('domain')->get();
    }

    protected function terminalAllowed(Request $request, Website $website): bool
    {
        return $this->files->terminalAvailable();
    }
}
