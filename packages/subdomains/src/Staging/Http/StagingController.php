<?php

declare(strict_types=1);

namespace Rhis\Subdomains\Staging\Http;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\Subdomains\Staging\Models\Staging;
use Rhis\Subdomains\Staging\StagingManager;
use Rhis\Subdomains\SubdomainException;

/**
 * WordPress staging pages, shared by the admin and the client panel. The admin
 * may act on every website, a customer only on their own (allowed()).
 */
abstract class StagingController extends Controller
{
    /** 'admin' or 'client': layout and route names. */
    protected string $area;

    public function __construct(protected readonly StagingManager $stagings) {}

    abstract protected function allowed(Request $request, Website $website): bool;

    protected function enabled(): bool
    {
        return true;
    }

    /** The staging page of a website: its staging copies, or a form to make one. */
    public function index(Request $request, Website $website): View|RedirectResponse
    {
        $this->authorizeSite($request, $website);
        if ($own = $this->stagings->stagingOf($website)) {
            return redirect()->route($this->area.'.staging.show', $own);
        }
        $list = Staging::where('live_website_id', $website->id)->latest('id')->get();
        foreach ($list->filter->busy() as $s) {
            $this->stagings->sync($s);
        }

        return view('subdomains::staging.index', [
            'area' => $this->area, 'website' => $website, 'stagings' => $list->fresh(),
            'paths' => $this->stagings->paths($website), 'blocker' => $this->stagings->blocker($website),
            'main' => $website->isSubdomain() ? $website->parent : $website,
        ]);
    }

    public function store(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeSite($request, $website);
        $data = $request->validate([
            'label' => ['required', 'string', 'max:63'],
            'path' => ['nullable', 'string', 'max:200'],
        ]);
        try {
            $staging = $this->stagings->start($website, strtolower(trim($data['label'])), (string) ($data['path'] ?? ''), $request->user()?->id);
        } catch (SubdomainException $e) {
            return back()->withInput()->withErrors(['label' => $e->getMessage()]);
        }

        return redirect()->route($this->area.'.staging.show', $staging)
            ->with('status', __('Making a staging copy of :domain on :staging. This takes a few minutes.', ['domain' => $staging->live_domain, 'staging' => $staging->staging_domain]));
    }

    public function show(Request $request, Staging $staging): View
    {
        $this->authorizeStaging($request, $staging);
        $this->stagings->sync($staging);
        $staging->refresh();
        $running = $staging->runningPush();
        $job = $running ? $this->stagings->syncPush($running) : null;
        $running = $staging->runningPush();
        $wp = $staging->staging && class_exists(\Rhis\WordPress\Models\WordPressInstall::class)
            ? \Rhis\WordPress\Models\WordPressInstall::where('website_id', $staging->staging_website_id)->where('path', $staging->path)->first() : null;

        return view('subdomains::staging.show', [
            'area' => $this->area, 'staging' => $staging->load(['live', 'staging', 'siteClone']),
            'plan' => $staging->isReady() && ! $running ? $this->stagings->plan($staging) : null,
            'backups' => $staging->isReady() ? $this->stagings->backups($staging) : [],
            'history' => $staging->pushes()->with('user')->limit(15)->get(),
            'running' => $running, 'job' => $job, 'showLog' => $this->area === 'admin', 'wp' => $wp,
        ]);
    }

    public function push(Request $request, Staging $staging): RedirectResponse
    {
        $this->authorizeStaging($request, $staging);
        $data = $request->validate([
            'files' => ['nullable', 'boolean'],
            'database' => ['nullable', 'boolean'],
            'keep_uploads' => ['nullable', 'boolean'],
            'keep_tables' => ['nullable', 'array', 'max:500'],
            'keep_tables.*' => ['string', 'regex:/^[A-Za-z0-9_$]{1,64}$/'],
            'confirm' => ['required', 'string', Rule::in([$staging->live_domain])],
        ], ['confirm.in' => __('Type :domain to confirm.', ['domain' => $staging->live_domain]),
            'confirm.required' => __('Type :domain to confirm.', ['domain' => $staging->live_domain])]);
        $files = (bool) ($data['files'] ?? false);
        $database = (bool) ($data['database'] ?? false);
        if (! $files && ! $database) {
            return back()->withInput()->withErrors(['push' => __('Choose what to push: files, database or both.')]);
        }
        $plan = $this->stagings->plan($staging);
        if (isset($plan['error']) || ! empty($plan['problems'])) {
            return back()->withInput()->withErrors(['push' => $plan['error'] ?? $plan['problems'][0]]);
        }
        $known = collect($plan['tables'] ?? [])->pluck('name')->all();
        $keep = array_values(array_intersect($data['keep_tables'] ?? [], $known));
        try {
            $this->stagings->push($staging, ['files' => $files, 'database' => $database, 'keep_tables' => $keep,
                'keep_uploads' => (bool) ($data['keep_uploads'] ?? false)], $request->user()?->id);
        } catch (SubdomainException $e) {
            return back()->withInput()->withErrors(['push' => $e->getMessage()]);
        }

        return redirect()->route($this->area.'.staging.show', $staging)
            ->with('status', __('Pushing :staging to :domain. A backup of the live website is made first.', ['staging' => $staging->staging_domain, 'domain' => $staging->live_domain]));
    }

    public function refresh(Request $request, Staging $staging): RedirectResponse
    {
        $this->authorizeStaging($request, $staging);
        $request->validate(['confirm' => ['required', 'string', Rule::in([$staging->staging_domain])]], [
            'confirm.in' => __('Type :domain to confirm.', ['domain' => $staging->staging_domain]),
            'confirm.required' => __('Type :domain to confirm.', ['domain' => $staging->staging_domain]),
        ]);
        try {
            $this->stagings->refresh($staging, $request->user()?->id);
        } catch (SubdomainException $e) {
            return back()->withErrors(['refresh' => $e->getMessage()]);
        }

        return redirect()->route($this->area.'.staging.show', $staging)
            ->with('status', __('Copying :domain over the staging copy again.', ['domain' => $staging->live_domain]));
    }

    public function restore(Request $request, Staging $staging): RedirectResponse
    {
        $this->authorizeStaging($request, $staging);
        $data = $request->validate(['backup' => ['required', 'string', 'regex:/^\d{8}-\d{6}$/']]);
        try {
            $this->stagings->restore($staging, $data['backup'], $request->user()?->id);
        } catch (SubdomainException $e) {
            return back()->withErrors(['restore' => $e->getMessage()]);
        }

        return redirect()->route($this->area.'.staging.show', $staging)
            ->with('status', __('Putting the backup back on :domain.', ['domain' => $staging->live_domain]));
    }

    public function destroy(Request $request, Staging $staging): RedirectResponse
    {
        $this->authorizeStaging($request, $staging);
        $removeSite = $request->boolean('remove_site');
        if ($removeSite) {
            $request->validate(['confirm' => ['required', 'string', Rule::in([$staging->staging_domain])]], [
                'confirm.in' => __('Type :domain to confirm.', ['domain' => $staging->staging_domain]),
                'confirm.required' => __('Type :domain to confirm.', ['domain' => $staging->staging_domain]),
            ]);
        }
        $live = $staging->live;
        try {
            $this->stagings->remove($staging, $removeSite);
        } catch (SubdomainException|AgentException $e) {
            return back()->withErrors(['remove' => $e->getMessage()]);
        }
        $to = $live ? route($this->area.'.websites.show', [$live, 'tab' => 'wordpress']) : route($this->area.'.websites.index');

        return redirect($to)->with('status', $removeSite
            ? __(':domain is being removed.', ['domain' => $staging->staging_domain])
            : __(':domain is no longer a staging copy.', ['domain' => $staging->staging_domain]));
    }

    protected function authorizeSite(Request $request, Website $website): void
    {
        abort_unless($this->enabled(), 403);
        abort_unless($this->allowed($request, $website), 404);
    }

    protected function authorizeStaging(Request $request, Staging $staging): void
    {
        abort_unless($this->enabled(), 403);
        $site = $staging->live ?? $staging->staging;
        abort_unless($site !== null && $this->allowed($request, $site), 404);
        if ($staging->staging && ! $this->allowed($request, $staging->staging)) {
            abort(404);
        }
    }
}
