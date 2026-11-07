<?php

declare(strict_types=1);

namespace Rhis\Certificates\Http;

use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Customers: certificates of their own websites. */
final class ClientCertificateController extends CertificateController
{
    protected string $area = 'client';

    protected function websites(Request $request): Builder
    {
        return Website::query()->where('customer_id', $request->user()->customer_id ?? -1);
    }

    protected function canManage(): bool
    {
        return (bool) config('certificates.customer_manage', true);
    }
}
