<?php

declare(strict_types=1);

namespace Rhis\Certificates\Http;

use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class AdminCertificateController extends CertificateController
{
    protected string $area = 'admin';

    protected function websites(Request $request): Builder
    {
        return Website::query()->with('customer');
    }
}
