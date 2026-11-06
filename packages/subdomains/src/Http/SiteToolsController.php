<?php

declare(strict_types=1);

namespace Rhis\Subdomains\Http;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\Subdomains\CloneManager;
use Rhis\Subdomains\Models\SiteClone;
use Rhis\Subdomains\SubdomainException;
use Rhis\Subdomains\SubdomainManager;

/**
 * Subdomains and cloning, shared by the admin and the client panel. The admin
 * may act on every website, a customer only on their own (allowed()).
 */
abstract class SiteToolsController extends Controller
{
    /** 'admin' or 'client': layout and route names. */
    protected string $area;

    public function __construct(protected readonly SubdomainManager $subdomains, protected readonly CloneManager $clones) {}

    abstract protected function allowed(Request $request, Website $website): bool;

    protected function canManageSubdomains(): bool
    {
        return true;
    }

    protected function canClone(): bool
    {
        return true;
    }

    // ---- subdomains ----------------------------------------------------------------

    public function storeSubdomain(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeSite($request, $website);
        abort_unless($this->canManageSubdomains(), 403);
        $data = $request->validate([
            'label' => ['required', 'string', 'max:63'],
            'profile' => ['nullable', Rule::in(Website::PROFILES)],
            'php_version' => ['nullable', Rule::in(Website::PHP_VERSIONS)],
        ]);
        $label = strtolower(trim($data['label']));
        if ($error = $this->subdomains->invalid($website, $label)) {
            return back()->withInput()->withErrors(['label' => $error]);
        }
        try {
            $sub = $this->subdomains->create($website, $label, array_filter([
                'profile' => $data['profile'] ?? null, 'php_version' => $data['php_version'] ?? null,
            ]));
        } catch (SubdomainException $e) {
            return back()->withInput()->withErrors(['label' => $e->getMessage()]);
        }

        return back()->with('status', __(':domain is being set up. This takes about a minute.', ['domain' => $sub->domain]))->withFragment('subdomains');
    }

    public function destroySubdomain(Request $request, Website $website, Website $subdomain): RedirectResponse
    {
        $this->authorizeSite($request, $website);
        abort_unless($this->canManageSubdomains() && $subdomain->parent_id === $website->id, 404);
        $request->validate(['confirm' => ['required', 'string', Rule::in([$subdomain->domain])]], [
            'confirm.in' => __('Type :domain to confirm.', ['domain' => $subdomain->domain]),
        ]);
        try {
            $this->subdomains->remove($subdomain);
        } catch (SubdomainException|AgentException $e) {
            return back()->withErrors(['subdomain' => $e->getMessage()])->withFragment('subdomains');
        }

        return back()->with('status', __(':domain is being removed.', ['domain' => $subdomain->domain]))->withFragment('subdomains');
    }

    // ---- cloning ---------------------------------------------------------------------

    public function createClone(Request $request, Website $website): View
    {
        $this->authorizeSite($request, $website);
        abort_unless($this->canClone(), 403);
        $targets = $this->clones->targets($website);
        $selected = $request->integer('target') ?: null;
        $target = $selected ? $targets->firstWhere('id', $selected) : null;
        $history = SiteClone::where(fn ($q) => $q->where('source_website_id', $website->id)->orWhere('target_website_id', $website->id))
            ->latest()->limit(10)->get();
        $this->clones->syncAll($history->filter->busy());

        return view('subdomains::clone.create', [
            'area' => $this->area, 'website' => $website, 'targets' => $targets, 'target' => $target,
            'plan' => $this->clones->plan($website, $target),
            'main' => $website->isSubdomain() ? $website->parent : $website,
            'canAddSubdomain' => $this->canManageSubdomains() && $this->subdomains->blocker($website->isSubdomain() ? $website->parent : $website) === null,
            'history' => $history->fresh(),
        ]);
    }

    public function storeClone(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeSite($request, $website);
        abort_unless($this->canClone(), 403);
        $data = $request->validate([
            'target' => ['required', 'string', 'max:20'],
            'new_label' => ['nullable', 'required_if:target,new', 'string', 'max:63'],
            'replace' => ['nullable', 'boolean'],
            'noindex' => ['nullable', 'boolean'],
            'confirm' => ['nullable', 'string', 'max:253'],
        ], ['new_label.required_if' => __('Choose a name for the new subdomain.')]);

        $target = null;
        if ($data['target'] === 'new') {
            abort_unless($this->canManageSubdomains(), 403);
            $main = $website->isSubdomain() ? $website->parent : $website;
            $label = strtolower(trim((string) $data['new_label']));
            if ($error = $this->subdomains->invalid($main, $label) ?? $this->subdomains->blocker($main)) {
                return back()->withInput()->withErrors(['new_label' => $error]);
            }
        } else {
            $target = $this->clones->targets($website)->firstWhere('id', (int) $data['target']);
            if ($target === null) {
                return back()->withInput()->withErrors(['target' => __('Choose one of your websites.')]);
            }
            $plan = $this->clones->plan($website, $target);
            $hasContent = ! empty($plan['target_content']);
            if ($hasContent && ! ($data['replace'] ?? false)) {
                return back()->withInput()->withErrors(['replace' => __(':domain already has a website. Tick the box to replace it.', ['domain' => $target->domain])]);
            }
            if ($hasContent && strtolower(trim((string) ($data['confirm'] ?? ''))) !== $target->domain) {
                return back()->withInput()->withErrors(['confirm' => __('Type :domain to confirm.', ['domain' => $target->domain])]);
            }
        }
        $plan ??= $this->clones->plan($website);
        if (isset($plan['error'])) {
            return back()->withInput()->withErrors(['target' => $plan['error']]);
        }
        if ($problem = collect($plan['installs'] ?? [])->firstWhere('problem')) {
            return back()->withInput()->withErrors(['target' => __('This website cannot be cloned: :problem', ['problem' => $problem['problem']])]);
        }

        try {
            $clone = $this->clones->start($website, [
                'target' => $target, 'new_label' => $data['new_label'] ?? null,
                'replace' => (bool) ($data['replace'] ?? false), 'noindex' => (bool) ($data['noindex'] ?? false),
                'user_id' => $request->user()?->id,
            ]);
        } catch (SubdomainException $e) {
            return back()->withInput()->withErrors(['target' => $e->getMessage()]);
        }

        return redirect()->route($this->area.'.clones.show', $clone)
            ->with('status', __('Cloning :source to :target. This can take a few minutes.', ['source' => $clone->source_domain, 'target' => $clone->target_domain]));
    }

    public function showClone(Request $request, SiteClone $clone): View
    {
        $website = $clone->source ?? $clone->target;
        abort_unless($website !== null && $this->allowed($request, $website), 404);
        $job = $this->clones->sync($clone);

        return view('subdomains::clone.show', ['area' => $this->area, 'clone' => $clone->fresh(['source', 'target']) ?? $clone,
            'job' => $job, 'showLog' => $this->area === 'admin']);
    }

    protected function authorizeSite(Request $request, Website $website): void
    {
        abort_unless($this->allowed($request, $website), 404);
    }
}
