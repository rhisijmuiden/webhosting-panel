<?php

declare(strict_types=1);

namespace Rhis\Backups\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Rhis\Backups\OneDrive;

/** The Microsoft app customers use to connect their OneDrive as a backup location. */
final class AdminOneDriveController extends Controller
{
    public function __construct(private readonly OneDrive $onedrive) {}

    public function update(Request $request): RedirectResponse
    {
        if ($request->boolean('remove')) {
            $this->onedrive->forget();
            ActivityLog::record('backup.onedrive_app_removed', 'onedrive');

            return back()->with('status', __('OneDrive for customers is turned off.'));
        }
        $data = $request->validate([
            'client_id' => ['required', 'uuid'],
            'client_secret' => [$this->onedrive->configured() ? 'nullable' : 'required', 'string', 'max:500', 'not_regex:/[\x00-\x1f]/'],
        ]);
        $this->onedrive->save(strtolower($data['client_id']), $data['client_secret'] ?? null);
        ActivityLog::record('backup.onedrive_app_saved', 'onedrive', ['client_id' => strtolower($data['client_id'])]);

        return back()->with('status', __('Microsoft app saved. Customers can now connect their OneDrive.'));
    }
}
