<?php

namespace App\Http\Controllers;

use App\Services\VersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Data for the Info dialog at the bottom of the menu. */
class InfoController extends Controller
{
    public function show(Request $request, VersionService $versions): JsonResponse
    {
        $details = $versions->details();
        if (! $request->user()->isAdmin()) {
            $details = ['version' => $details['version'], 'channel' => $details['channel']];
        }

        return response()->json($details + [
            'app' => config('app.name'),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
        ]);
    }
}
