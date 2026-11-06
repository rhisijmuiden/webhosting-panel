<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Http\Api;

use App\Services\VersionService;
use Illuminate\Http\JsonResponse;

final class SystemController
{
    public function ping(VersionService $version): JsonResponse
    {
        return response()->json(['ok' => true, 'panel_version' => $version->version(), 'api_version' => '1.0.0', 'server' => gethostname() ?: null]);
    }
}
