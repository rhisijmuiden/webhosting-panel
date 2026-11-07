<?php

namespace App\Http\Controllers\Databases;

use App\Http\Controllers\Controller;
use App\Models\DatabaseUser;
use App\Models\SiteDatabase;
use App\Models\Website;
use App\Services\Agent\AgentException;
use App\Services\Databases\DatabaseException;
use App\Services\Databases\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Database screens shared by admins and customers; the subclasses decide whose websites. */
abstract class DatabaseController extends Controller
{
    /** 'admin' or 'client': route names and layout. */
    protected string $area;

    private const SUFFIX = '/^[a-z0-9][a-z0-9_]{0,31}$/';

    private const PASSWORD = '/^[\x20-\x7e]{12,128}$/';

    public function __construct(protected readonly DatabaseManager $dbs) {}

    /** The customer whose websites this user may manage (null = all, for admins). */
    abstract protected function customerId(Request $request): ?int;

    protected function route(string $name, mixed $params = []): string
    {
        return route("{$this->area}.databases.{$name}", $params);
    }

    protected function websites(Request $request): Builder
    {
        return Website::query()->where('status', '!=', 'removing')
            ->when($this->customerId($request), fn ($q, $id) => $q->where('customer_id', $id));
    }

    protected function authorizeWebsite(Request $request, Website $website): void
    {
        $id = $this->customerId($request);
        abort_if($website->status === 'removing' || ($id !== null && $website->customer_id !== $id), 404);
    }

    public function index(Request $request): View|RedirectResponse
    {
        $query = $this->websites($request)->with(['customer', 'databases'])->withCount('databases')->orderBy('domain');
        if ($this->area === 'admin') {
            $q = trim((string) $request->query('q'));
            $query->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('domain', 'like', "%{$q}%")
                ->orWhereHas('databases', fn ($d) => $d->where('name', 'like', "%{$q}%"))
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$q}%"))));
            $query->when($request->boolean('with'), fn ($w) => $w->has('databases'));
            $websites = $query->paginate(25)->withQueryString();
        } else {
            $websites = $query->get();
            if ($websites->count() === 1) {
                return redirect($this->route('show', $websites->first()));
            }
        }

        return view('databases.index', [
            'area' => $this->area, 'websites' => $websites, 'sizes' => $this->dbs->sizes() ?? [],
            'q' => $request->query('q'), 'with' => $request->boolean('with'),
        ]);
    }

    public function show(Request $request, Website $website): View
    {
        $this->authorizeWebsite($request, $website);
        $live = $this->dbs->sync($website);

        return view('databases.show', [
            'area' => $this->area, 'website' => $website->load('customer'), 'live' => $live,
            'databases' => SiteDatabase::where('website_id', $website->id)->orderBy('name')->get(),
            'users' => DatabaseUser::where('website_id', $website->id)->orderBy('name')->get(),
            'prefix' => DatabaseManager::prefix($website), 'limit' => DatabaseManager::limit($website),
            'used' => DatabaseManager::used($website), 'left' => DatabaseManager::left($website),
            'phpMyAdmin' => (bool) config('panel.phpmyadmin', true),
        ]);
    }

    public function store(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeWebsite($request, $website);
        $data = $request->validateWithBag('database', [
            'name' => ['required', 'string', 'regex:'.self::SUFFIX],
            'password' => ['nullable', 'string', 'regex:'.self::PASSWORD],
        ], $this->messages());
        if (strlen(DatabaseManager::prefix($website).$data['name']) > 64) {
            return back()->withInput()->withErrors(['name' => __('The name is too long.')], 'database');
        }
        try {
            $out = $this->dbs->create($website, $data['name'], $data['password'] ?? null);
        } catch (DatabaseException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()], 'database');
        } catch (AgentException $e) {
            return back()->withInput()->withErrors(['name' => $this->agentError($e)], 'database');
        }

        return redirect($this->route('show', $website))
            ->with('status', __('Database :name created.', ['name' => $out['name']]))
            ->with('db_credentials', ['database' => $out['name'], 'user' => $out['user'], 'password' => $out['password'], 'chosen' => filled($data['password'] ?? null)]);
    }

    public function destroy(Request $request, SiteDatabase $database): RedirectResponse
    {
        $this->authorizeWebsite($request, $database->website);
        $request->validate(['confirm' => ['required', Rule::in([$database->name])]], [
            'confirm.*' => __('Type the name of the database to delete it.'),
        ]);
        try {
            $this->dbs->drop($database);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $this->agentError($e)]);
        }

        return redirect($this->route('show', $database->website))->with('status', __('Database :name deleted.', ['name' => $database->name]));
    }

    public function phpmyadmin(Request $request, SiteDatabase $database): RedirectResponse
    {
        $this->authorizeWebsite($request, $database->website);
        $key = 'phpmyadmin:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($key, 20)) {
            return back()->withErrors(['agent' => __('Too many phpMyAdmin sign-ins. Try again in a minute.')]);
        }
        RateLimiter::hit($key, 60);
        try {
            return redirect()->away($this->dbs->phpMyAdminUrl($database, app()->getLocale()));
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->errorCode === 'unavailable'
                ? __('phpMyAdmin is not installed on this server yet.') : $this->agentError($e)]);
        }
    }

    public function userStore(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeWebsite($request, $website);
        $names = SiteDatabase::where('website_id', $website->id)->pluck('name')->all();
        $data = $request->validateWithBag('user', [
            'name' => ['required', 'string', 'regex:'.self::SUFFIX],
            'databases' => ['required', 'array', 'min:1'],
            'databases.*' => ['string', Rule::in($names)],
            'readonly' => ['boolean'],
            'password' => ['nullable', 'string', 'regex:'.self::PASSWORD],
        ], $this->messages() + ['databases.required' => __('Choose at least one database.')]);
        try {
            $out = $this->dbs->createUser($website, $data['name'], $data['databases'], $request->boolean('readonly'), $data['password'] ?? null);
        } catch (AgentException $e) {
            return back()->withInput()->withErrors(['name' => $this->agentError($e)], 'user');
        }

        return redirect($this->route('show', $website))
            ->with('status', __('Database user :name created.', ['name' => $out['user']]))
            ->with('db_credentials', ['database' => implode(', ', $out['databases']), 'user' => $out['user'], 'password' => $out['password'], 'chosen' => filled($data['password'] ?? null)]);
    }

    public function userUpdate(Request $request, DatabaseUser $dbuser): RedirectResponse
    {
        $this->authorizeWebsite($request, $dbuser->website);
        $names = SiteDatabase::where('website_id', $dbuser->website_id)->pluck('name')->all();
        $data = $request->validate([
            'databases' => ['required', 'array', 'min:1'],
            'databases.*' => ['string', Rule::in($names)],
            'readonly' => ['boolean'],
        ], ['databases.required' => __('Choose at least one database.')]);
        try {
            $this->dbs->updateUser($dbuser, $data['databases'], $request->boolean('readonly'));
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $this->agentError($e)]);
        }

        return back()->with('status', __('Access of :name saved.', ['name' => $dbuser->name]));
    }

    public function userDestroy(Request $request, DatabaseUser $dbuser): RedirectResponse
    {
        $this->authorizeWebsite($request, $dbuser->website);
        try {
            $this->dbs->dropUser($dbuser);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $this->agentError($e)]);
        }

        return back()->with('status', __('Database user :name deleted.', ['name' => $dbuser->name]));
    }

    public function password(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeWebsite($request, $website);
        $users = SiteDatabase::where('website_id', $website->id)->get()->flatMap(fn ($d) => $d->users ?: [$d->name])
            ->merge(DatabaseUser::where('website_id', $website->id)->pluck('name'))->unique()->values()->all();
        $data = $request->validateWithBag('password', [
            'user' => ['required', 'string', Rule::in($users)],
            'password' => ['nullable', 'string', 'regex:'.self::PASSWORD],
        ], $this->messages());
        try {
            $out = $this->dbs->resetPassword($website, $data['user'], $data['password'] ?? null);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $this->agentError($e)]);
        }

        return back()->with('status', __('New password for :name saved.', ['name' => $out['user']]))
            ->with('db_credentials', ['database' => null, 'user' => $out['user'], 'password' => $out['password'], 'chosen' => filled($data['password'] ?? null)]);
    }

    private function messages(): array
    {
        return [
            'name.regex' => __('Use lowercase letters, digits and _ only (at most 32), starting with a letter or digit.'),
            'password.regex' => __('Use 12 to 128 characters, without accents or emoji.'),
        ];
    }

    private function agentError(AgentException $e): string
    {
        return match ($e->errorCode) {
            'conflict' => __('This name is already in use. Choose another name.'),
            'unreachable' => __('The server did not answer. Try again in a moment.'),
            default => __($e->getMessage()),
        };
    }
}
