<?php

declare(strict_types=1);

namespace Rhis\Files\Http;

use App\Models\Website;
use Illuminate\Http\Request;

final class ClientFileController extends FileController
{
    protected string $area = 'client';

    protected function authorizeSite(Request $request, Website $website): void
    {
        abort_unless(config('files.enabled'), 404);
        abort_unless($website->customer_id === $request->user()->customer_id, 404);
        abort_if($website->status === 'suspended', 403, __('This website is suspended.'));
    }

    protected function websites(Request $request)
    {
        abort_unless(config('files.enabled'), 404);

        return $request->user()->customer->websites()->orderBy('domain')->get();
    }

    /** Only when the customer's hosting package allows SSH. */
    protected function terminalAllowed(Request $request, Website $website): bool
    {
        return (bool) $request->user()->customer?->package?->allow_ssh && $this->files->terminalAvailable();
    }
}
