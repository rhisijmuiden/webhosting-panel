<?php

declare(strict_types=1);

namespace Rhis\WordPress\DbCleanup;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Agent\AgentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\WordPress\Models\WordPressInstall;

/** Database cleanup screens; the subclasses decide who may see which install. */
abstract class DbCleanupController extends Controller
{
    /** 'admin' or 'client'. */
    protected string $area;

    public function __construct(protected readonly Cleaner $cleaner) {}

    abstract protected function authorizeInstall(Request $request, WordPressInstall $install): void;

    public function show(Request $request, WordPressInstall $install): View
    {
        $this->authorizeInstall($request, $install);
        $keep = (int) $request->query('keep', '0');
        $keep = in_array($keep, Cleaner::KEEP_REVISIONS, true) ? $keep : 0;
        $preview = null;
        $errors = [];
        try {
            $preview = $this->cleaner->preview($install, $keep);
        } catch (AgentException $e) {
            $errors[] = $e->getMessage();
        }
        $history = DbCleanup::with('user')->where('wordpress_install_id', $install->id)->latest('id')->limit(10)->get();
        foreach ($history->where('status', 'running') as $running) {
            $this->cleaner->sync($running);
        }

        return view("wordpress::dbclean.{$this->area}.show", [
            'install' => $install, 'preview' => $preview, 'keep' => $keep, 'history' => $history,
            'agentErrors' => $errors, 'area' => $this->area,
        ]);
    }

    public function run(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['string', Rule::in(Cleaner::ITEMS)],
            'keep_revisions' => ['required', 'integer', Rule::in(Cleaner::KEEP_REVISIONS)],
        ], ['items.required' => __('Choose what to clean up.')]);
        if (DbCleanup::where('wordpress_install_id', $install->id)->where('status', 'running')->exists()) {
            return back()->withErrors(['agent' => __('A cleanup of this site is still running.')]);
        }
        try {
            $cleanup = $this->cleaner->start($install, $data['items'], (int) $data['keep_revisions'], $request->user()?->id);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('wordpress.db_cleanup', $install->label(), ['items' => $cleanup->items, 'keep_revisions' => $cleanup->keep_revisions],
            $install->website->customer_id);

        return redirect()->route("{$this->area}.wordpress.dbclean.status", $cleanup);
    }

    public function status(Request $request, DbCleanup $cleanup): View
    {
        $install = $cleanup->install()->with('website')->firstOrFail();
        $this->authorizeInstall($request, $install);
        $errors = [];
        $job = null;
        try {
            $job = $this->cleaner->sync($cleanup);
        } catch (AgentException $e) {
            $errors[] = $e->getMessage();
        }

        return view("wordpress::dbclean.{$this->area}.status", [
            'install' => $install, 'cleanup' => $cleanup->fresh(), 'job' => $job, 'agentErrors' => $errors, 'area' => $this->area,
        ]);
    }
}
