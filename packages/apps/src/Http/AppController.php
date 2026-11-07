<?php

declare(strict_types=1);

namespace Rhis\Apps\Http;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\Apps\AppInstaller;
use Rhis\Apps\Models\AppInstallation;
use Rhis\Apps\PluginSets\PluginSet;

/**
 * Shared by the admin and the client panel. The admin sees every website,
 * a customer only their own (scopeWebsites / scopeInstallations).
 */
abstract class AppController extends Controller
{
    /** 'admin' or 'client': layout and route names. */
    protected string $area;

    public function __construct(protected readonly AppInstaller $installer) {}

    abstract protected function websites(Request $request): Builder;

    abstract protected function installations(Request $request): Builder;

    protected function databasesLeft(Request $request): ?int
    {
        return null;
    }

    /** Whether the package of this website allows one more database (admins are not limited). */
    protected function hasDatabaseRoom(Website $website): bool
    {
        return true;
    }

    public function index(Request $request): View
    {
        $installations = $this->installations($request)->with('website')->latest()->get();
        $this->installer->syncAll($installations->filter->busy());
        $installations = $this->installations($request)->with('website.customer')->latest()->get();

        // The admin also sees the apps that are held back (and why); a customer only what can be installed.
        $catalog = $this->area === 'admin' ? $this->installer->catalog() : $this->installer->offered();
        $categories = collect(config('apps.categories', []))
            ->filter(fn ($label, $key) => collect($catalog)->contains('category', $key))->all();
        $category = array_key_exists((string) $request->query('category'), $categories) ? (string) $request->query('category') : null;

        return view('apps::index', [
            'area' => $this->area,
            'catalog' => $category ? array_filter($catalog, fn ($a) => $a['category'] === $category) : $catalog,
            'categories' => $categories,
            'category' => $category,
            'installations' => $installations,
            'updates' => $this->installer->refreshVersions($installations),
            'website' => $request->integer('website') ?: null,
            'databasesLeft' => $this->databasesLeft($request),
            'maxAge' => $this->installer->maxAge(),
        ]);
    }

    public function create(Request $request, string $app): View|RedirectResponse
    {
        $meta = $this->installer->app($app) ?? abort(404);
        $websites = $this->websites($request)->where('status', 'active')->with('customer')->orderBy('domain')->get();
        $left = $this->databasesLeft($request);

        return view('apps::install', [
            'area' => $this->area, 'app' => $app, 'meta' => $meta, 'websites' => $websites,
            'selected' => $request->integer('website') ?: $websites->first()?->id,
            'databasesLeft' => ($meta['database'] ?? true) ? $left : null,
            'defaults' => $this->defaults($request),
            ...$this->pluginSetChoices($app, $websites),
        ]);
    }

    /**
     * WordPress plugin sets for the install form: the sets to pick from and the default
     * set per website (from the website's package).
     */
    protected function pluginSetChoices(string $app, $websites): array
    {
        if (! in_array($app, PluginSet::APPS, true)) {
            return ['pluginSets' => collect(), 'pluginSetDefaults' => []];
        }
        $byPackage = PluginSet::defaultsByPackage();
        $defaults = [];
        foreach ($websites as $w) {
            $defaults[$w->id] = $byPackage[$w->package?->id] ?? null;
        }
        $sets = PluginSet::query()->orderBy('name')
            ->when($this->area !== 'admin', fn ($q) => $q->where(fn ($q) => $q->where('customer_visible', true)
                ->orWhereIn('id', array_filter($defaults))))
            ->get();

        return ['pluginSets' => $sets, 'pluginSetDefaults' => $defaults];
    }

    public function store(Request $request, string $app): RedirectResponse
    {
        $meta = $this->installer->app($app) ?? abort(404);
        $data = $request->validate([
            'website' => ['required', 'integer'],
            'path' => array_filter(['nullable', 'string', 'max:170', 'regex:#^/?[a-z0-9][a-z0-9_-]{0,39}(/[a-z0-9][a-z0-9_-]{0,39}){0,3}/?$#',
                $meta['subdir'] ? null : 'prohibited']),
            'title' => ['required', 'string', 'max:100', 'not_regex:/[\r\n]/'],
            'admin_user' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9._@-]{2,59}$/',
                ...($this->userPattern($meta) ? ['regex:/^'.$this->userPattern($meta).'$/'] : [])],
            'admin_email' => ['required', 'email', 'max:254'],
            'admin_password' => ['required', 'string', 'min:12', 'max:128', 'not_regex:/[\r\n]/'],
            'locale' => ['required', Rule::in($meta['locales'])],
        ], [
            'path.regex' => __('Use lowercase letters, digits, - and _ only, for example blog or shop.'),
            'path.prohibited' => __(':app can only be installed in the root of a website.', ['app' => $meta['name']]),
            'admin_user.regex' => isset($meta['admin_user_hint'])
                ? __($meta['admin_user_hint'])
                : __('Use letters, digits, dots, @, - and _ only.'),
        ]);
        $website = $this->websites($request)->where('status', 'active')->findOrFail($data['website']);
        if (($meta['database'] ?? true) && ! $this->hasDatabaseRoom($website)) {
            return back()->withInput()->withErrors(['agent' => __('Your package allows no more databases. Remove an app or ask us for a larger package.')]);
        }
        $data['path'] = trim((string) ($data['path'] ?? ''), '/');
        if (in_array($app, PluginSet::APPS, true) && $request->filled('plugin_set')) {
            $set = PluginSet::choicesFor($website, $this->area === 'admin')->firstWhere('id', $request->integer('plugin_set'));
            if (! $set) {
                return back()->withInput($request->except('admin_password'))->withErrors(['plugin_set' => __('Choose one of the plugin sets in the list.')]);
            }
            $data['plugin_set'] = $set;
        }

        try {
            $installation = $this->installer->install($website, $app, $data);
        } catch (AgentException $e) {
            return back()->withInput($request->except('admin_password'))->withErrors(['agent' => $this->message($e)]);
        }

        return redirect()->route($this->area.'.apps.show', $installation)
            ->with('status', __(':app is being installed. This takes one or two minutes.', ['app' => $meta['name']]));
    }

    public function show(Request $request, AppInstallation $installation): View|RedirectResponse
    {
        $this->authorizeInstallation($request, $installation);
        $job = $this->installer->sync($installation);
        if (! $installation->exists) {
            return redirect()->route($this->area.'.apps.index')
                ->with('status', __(':app was removed.', ['app' => $installation->meta()['name']]));
        }

        $installation = $installation->fresh(['website']) ?? $installation;

        return view('apps::show', [
            'area' => $this->area, 'installation' => $installation,
            'job' => $job, 'showLog' => $this->area === 'admin',
            'update' => $this->installer->refreshVersions([$installation])[$installation->id] ?? null,
        ]);
    }

    public function destroy(Request $request, AppInstallation $installation): RedirectResponse
    {
        $this->authorizeInstallation($request, $installation);
        if ($installation->busy()) {
            return back()->withErrors(['agent' => __('Wait until the current task is finished.')]);
        }
        $name = $installation->meta()['name'];
        $failed = $installation->status === 'failed';
        try {
            $this->installer->remove($installation);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $this->message($e)]);
        }
        if ($failed || ! $installation->exists) {
            return redirect()->route($this->area.'.apps.index')->with('status', __('Removed.'));
        }

        return redirect()->route($this->area.'.apps.show', $installation)
            ->with('status', __(':app is being removed.', ['app' => $name]));
    }

    protected function authorizeInstallation(Request $request, AppInstallation $installation): void
    {
        abort_unless($this->installations($request)->whereKey($installation->id)->exists(), 404);
    }

    protected function defaults(Request $request): array
    {
        $user = $request->user();
        $email = $user->email ?? '';

        // Never suggest a name attackers try first (admin, root, ...).
        $name = Str::of(Str::before($email, '@'))->lower()->replaceMatches('/[^a-z0-9._-]/', '')->limit(30, '')->value();
        if (strlen($name) < 3 || in_array($name, ['admin', 'administrator', 'root', 'info', 'webmaster', 'test'], true)) {
            $name = 'beheer-'.Str::lower(Str::random(4));
        }

        return [
            'admin_user' => $name,
            'admin_email' => $email,
            'locale' => in_array(app()->getLocale(), ['en', 'nl'], true) ? app()->getLocale() : 'en',
        ];
    }

    /** The agent's stricter rule for admin names of this app (simple character classes only). */
    protected function userPattern(array $meta): ?string
    {
        $p = $meta['admin_user_pattern'] ?? null;

        return is_string($p) && preg_match('#^[A-Za-z0-9\[\]\-._@{},]+$#', $p) ? $p : null;
    }

    protected function message(AgentException $e): string
    {
        return in_array($e->errorCode, ['conflict', 'invalid_params', 'not_found', 'forbidden'], true)
            ? $e->getMessage()
            : __('The server could not start the installation. Please try again later.');
    }
}
