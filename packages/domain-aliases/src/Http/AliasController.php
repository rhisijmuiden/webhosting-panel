<?php

declare(strict_types=1);

namespace Rhis\DomainAliases\Http;

use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\DomainAliases\AliasException;
use Rhis\DomainAliases\AliasManager;
use Rhis\DomainAliases\Models\DomainAlias;

/** Shared by the admin and client panel; subclasses scope what a user may touch. */
abstract class AliasController
{
    protected string $area = 'admin';

    public function __construct(protected readonly AliasManager $manager) {}

    abstract protected function websites(Request $request): Builder;

    protected function enforceLimit(): bool
    {
        return true;
    }

    public function index(Request $request): View
    {
        $websites = $this->websites($request)->orderBy('domain')->get();
        $aliases = DomainAlias::with('website.customer')->whereIn('website_id', $websites->modelKeys())->orderBy('domain')->get();

        return view('domain-aliases::index', [
            'area' => $this->area, 'aliases' => $aliases,
            'websites' => $websites->where('status', 'active')->values(),
            'manager' => $this->manager,
        ]);
    }

    public function store(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeWebsite($request, $website);
        $request->merge(['domain' => AliasManager::normalise((string) $request->input('domain'))]);
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:'.AliasManager::DOMAIN],
            'mode' => ['required', Rule::in(DomainAlias::MODES)],
            'www' => ['sometimes', 'boolean'],
            'mail' => ['sometimes', 'boolean'],
        ], ['domain.regex' => __('That is not a valid domain name.')]);
        try {
            $alias = $this->manager->add($website, [
                'domain' => $data['domain'], 'mode' => $data['mode'],
                'www' => $request->boolean('www'), 'mail' => $request->boolean('mail'),
            ], $this->area === 'admin' ? 'panel' : 'client', $this->enforceLimit());
        } catch (AliasException $e) {
            return back()->withInput()->withErrors(['domain' => $e->getMessage()]);
        }

        return back()->with('status', __(':domain is being added. The certificate follows as soon as DNS points to this server.', ['domain' => $alias->domain]));
    }

    public function update(Request $request, DomainAlias $alias): RedirectResponse
    {
        $this->authorizeAlias($request, $alias);
        $data = $request->validate([
            'mode' => ['sometimes', Rule::in(DomainAlias::MODES)],
            'mail' => ['sometimes', 'boolean'],
        ]);
        $warnings = [];
        try {
            if (isset($data['mode'])) {
                $this->manager->setMode($alias, $data['mode']);
            }
            if (array_key_exists('mail', $data)) {
                $warnings = $this->manager->setMail($alias, (bool) $data['mail']);
            }
        } catch (AliasException $e) {
            return back()->withErrors(['alias' => $e->getMessage()]);
        }

        return back()->with('status', __('Domain alias saved.'))->with('warnings', $warnings);
    }

    public function ssl(Request $request, DomainAlias $alias): RedirectResponse
    {
        $this->authorizeAlias($request, $alias);
        try {
            $this->manager->retrySsl($alias);
        } catch (AliasException $e) {
            return back()->withErrors(['alias' => $e->getMessage()]);
        }

        return back()->with('status', $alias->fresh()->ssl_status === 'active'
            ? __('Certificate issued for :domain.', ['domain' => $alias->domain])
            : __('No certificate yet: :domain does not point to this server yet.', ['domain' => $alias->domain]));
    }

    public function destroy(Request $request, DomainAlias $alias): RedirectResponse
    {
        $this->authorizeAlias($request, $alias);
        try {
            $this->manager->remove($alias, $this->area === 'admin' ? 'panel' : 'client');
        } catch (AliasException $e) {
            return back()->withErrors(['alias' => $e->getMessage()]);
        }

        return back()->with('status', __(':domain removed.', ['domain' => $alias->domain]));
    }

    public function convertForm(Request $request, DomainAlias $alias): View
    {
        $this->authorizeAlias($request, $alias);

        return view('domain-aliases::convert', [
            'area' => $this->area, 'alias' => $alias->load('website.customer.package'),
            'direct' => $this->area === 'admin' || $this->manager->canConvertDirectly($alias),
            'products' => $this->manager->upgradeProducts(),
            'order' => $alias->upgradeOrder(),
        ]);
    }

    public function convert(Request $request, DomainAlias $alias): RedirectResponse
    {
        $this->authorizeAlias($request, $alias);
        $request->validate(['confirm' => ['required', 'in:'.$alias->domain]], ['confirm.in' => __('Type the domain name exactly to confirm.')]);
        abort_unless($this->area === 'admin' || $this->manager->canConvertDirectly($alias), 403);
        try {
            $website = $this->manager->convert($alias, $this->area === 'admin' ? 'panel' : 'client');
        } catch (AliasException $e) {
            return back()->withErrors(['alias' => $e->getMessage()]);
        }

        return redirect()->route($this->area.'.websites.show', $website)
            ->with('status', __(':domain is now a website of its own. It is being set up.', ['domain' => $website->domain]));
    }

    protected function authorizeWebsite(Request $request, Website $website): void
    {
        abort_unless($this->websites($request)->whereKey($website->id)->exists(), 404);
    }

    protected function authorizeAlias(Request $request, DomainAlias $alias): void
    {
        abort_unless($this->websites($request)->whereKey($alias->website_id)->exists(), 404);
    }
}
