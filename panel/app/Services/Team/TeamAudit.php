<?php

namespace App\Services\Team;

use App\Models\ActivityLog;
use App\Models\Website;
use App\Support\TeamAreas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The audit log of the client panel: every change a client login makes is
 * kept with who did it. Features that write their own activity entry keep
 * theirs; for every other change one "client.change" entry is written.
 */
class TeamAudit
{
    private static int $written = 0;

    /** Verbs for the last part of a route name, for the label of a change. */
    private const VERBS = [
        'store' => 'Added', 'create' => 'Added', 'update' => 'Changed', 'destroy' => 'Deleted', 'delete' => 'Deleted',
        'restore' => 'Restored', 'run' => 'Started', 'deploy' => 'Deployed', 'rollback' => 'Rolled back',
        'upload' => 'Uploaded', 'write' => 'Saved', 'rename' => 'Renamed', 'move' => 'Moved', 'copy' => 'Copied',
        'extract' => 'Extracted', 'compress' => 'Compressed', 'chmod' => 'Permissions changed', 'password' => 'Password changed',
        'ssl' => 'Certificate requested', 'php' => 'PHP settings changed', 'scan' => 'Scan started', 'cancel' => 'Cancelled',
        'release' => 'Released', 'test' => 'Tested', 'check' => 'Checked', 'login' => 'Logged in', 'convert' => 'Converted',
    ];

    public static function written(): void
    {
        self::$written++;
    }

    public function count(): int
    {
        return self::$written;
    }

    public function after(Request $request, Response $response, int $before): void
    {
        if ($request->isMethodSafe() || $response->getStatusCode() >= 400 || self::$written > $before) {
            return;
        }
        if ($request->hasSession() && $request->session()->has('errors')) {
            return; // the form came back with validation errors: nothing changed
        }
        $route = $request->route()?->getName();
        if ($route === null) {
            return;
        }

        ActivityLog::record('client.change', $this->subject($request), [
            'route' => $route,
            'area' => TeamAreas::areaFor($route),
            'method' => $request->method(),
        ]);
    }

    /** "Files: Uploaded", "Email: Deleted", ... for a client.change entry. */
    public static function describe(ActivityLog $log): string
    {
        $route = (string) ($log->properties['route'] ?? '');
        $area = $log->properties['area'] ?? TeamAreas::areaFor($route);
        $label = $area ? __(TeamAreas::all()[$area]['label'] ?? $area) : __('Client panel');
        $last = Str::afterLast($route, '.');
        $verb = self::VERBS[$last] ?? match ($log->properties['method'] ?? 'POST') {
            'DELETE' => 'Deleted',
            'PUT', 'PATCH' => 'Changed',
            default => Str::of($last)->replace(['-', '_'], ' ')->ucfirst()->value() ?: 'Changed',
        };

        return $label.': '.__($verb);
    }

    /** The domain the change was about, taken from the route's models. */
    private function subject(Request $request): ?string
    {
        foreach ($request->route()?->parameters() ?? [] as $value) {
            if ($value instanceof Website) {
                return $value->domain;
            }
            if ($value instanceof Model) {
                if ($value->getAttribute('website_id')) {
                    return Website::withoutGlobalScopes()->whereKey($value->getAttribute('website_id'))->value('domain');
                }
                if (is_string($value->getAttribute('domain'))) {
                    return $value->getAttribute('domain');
                }
            }
        }
        $site = $request->input('website') ?? $request->input('website_id');
        if (is_numeric($site)) {
            return Website::withoutGlobalScopes()->whereKey((int) $site)->value('domain');
        }

        return null;
    }
}
