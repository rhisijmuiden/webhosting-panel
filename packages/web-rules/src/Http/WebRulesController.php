<?php

declare(strict_types=1);

namespace Rhis\WebRules\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Rhis\WebRules\Models\SiteWebRules;
use Rhis\WebRules\WebRules;

/**
 * Redirects & access of one website: redirects, password-protected folders,
 * error pages, HTTPS/HSTS and IP/country blocking. Every change is applied
 * on the server right away; when the server refuses it nothing is saved.
 */
abstract class WebRulesController extends Controller
{
    public const TABS = ['redirects', 'folders', 'errors', 'https', 'access'];

    /** client | admin: route prefix and layout. */
    protected string $area = 'client';

    public function __construct(protected readonly WebRules $rules) {}

    abstract protected function authorizeSite(Request $request, Website $website): void;

    public function show(Request $request, Website $website): View
    {
        $this->authorizeSite($request, $website);
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'redirects';

        return view('web-rules::show', [
            'area' => $this->area,
            'website' => $website,
            'rules' => $this->rules->forSite($website),
            'tab' => $tab,
            'tabs' => self::TABS,
            'status' => $tab === 'access' || $tab === 'https' ? $this->rules->status($website) : null,
            'ip' => (string) $request->ip(),
            'max' => config('web-rules'),
        ]);
    }

    // ------------------------------------------------------------ redirects

    public function storeRedirect(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeSite($request, $website);
        $data = $request->validate([
            'from' => ['required', 'string', 'max:2000'],
            'to' => ['required', 'string', 'max:2000'],
            'code' => ['required', 'integer', Rule::in(WebRules::REDIRECT_CODES)],
            'prefix' => ['nullable', 'boolean'],
            'keep_query' => ['nullable', 'boolean'],
        ]);
        $from = $this->sitePath($website, $data['from']);
        if ($from === null) {
            throw ValidationException::withMessages(['from' => __('Enter a path on this website, such as /old-page.')]);
        }
        $to = $this->target(trim($data['to']));
        if ($to === null) {
            throw ValidationException::withMessages(['to' => __('Enter a full address (https://...) or a path on this website (/new-page).')]);
        }
        $prefix = (bool) ($data['prefix'] ?? false);
        if (str_starts_with($to, '/') && rtrim(explode('?', $to, 2)[0], '/') === rtrim($from, '/')) {
            throw ValidationException::withMessages(['to' => __('A page cannot redirect to itself.')]);
        }
        if ($prefix && $from === '/' && str_starts_with($to, '/')) {
            throw ValidationException::withMessages(['to' => __('Redirecting the whole website to itself would never end. Use a full address of another website.')]);
        }

        $rules = $this->rules->forSite($website);
        $list = $rules->redirectList();
        if (collect($list)->contains(fn ($r) => $r['from'] === $from && (bool) $r['prefix'] === $prefix)) {
            throw ValidationException::withMessages(['from' => __('There already is a redirect for :path.', ['path' => $from])]);
        }
        if (count($list) >= (int) config('web-rules.max_redirects')) {
            throw ValidationException::withMessages(['from' => __('A website can have at most :max redirects.', ['max' => config('web-rules.max_redirects')])]);
        }
        $list[] = ['id' => WebRules::id(), 'from' => $from, 'to' => $to, 'code' => (int) $data['code'],
            'prefix' => $prefix, 'keep_query' => (bool) ($data['keep_query'] ?? false)];
        $rules->redirects = $list;

        return $this->commit($website, $rules, 'redirects', 'redirect_added', ['from' => $from, 'to' => $to],
            __('Redirect from :from added.', ['from' => $from]));
    }

    public function destroyRedirect(Request $request, Website $website, string $id): RedirectResponse
    {
        $this->authorizeSite($request, $website);
        $rules = $this->rules->forSite($website);
        $gone = collect($rules->redirectList())->firstWhere('id', $id);
        abort_if($gone === null, 404);
        $rules->redirects = array_values(array_filter($rules->redirectList(), fn ($r) => $r['id'] !== $id));

        return $this->commit($website, $rules, 'redirects', 'redirect_removed', ['from' => $gone['from']],
            __('Redirect from :from removed.', ['from' => $gone['from']]));
    }

    // ------------------------------------------------------------ protected folders

    public function storeFolder(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeSite($request, $website);
        $data = $request->validate([
            'path' => ['required', 'string', 'max:200'],
            'realm' => ['nullable', 'string', 'max:60', 'regex:/^[^"\'\\\\${};]*$/'],
            'username' => ['required', 'string', 'regex:/^[A-Za-z0-9._@-]{1,64}$/'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
        ], ['realm.regex' => __('The title cannot contain quotes, braces, semicolons or $.'),
            'username.regex' => __('Use letters, digits and . _ @ - only.')]);
        $path = $this->sitePath($website, $data['path']);
        if ($path === null) {
            throw ValidationException::withMessages(['path' => __('Enter a folder on this website, such as /admin, or / for the whole website.')]);
        }
        $realm = trim((string) ($data['realm'] ?? '')) ?: 'Restricted area';
        if (strtolower($realm) === 'off') {
            $realm = 'Restricted area';
        }
        $rules = $this->rules->forSite($website);
        $list = $rules->folderList();
        if (collect($list)->contains('path', $path)) {
            throw ValidationException::withMessages(['path' => __(':path is already protected. Add a user to it instead.', ['path' => $path])]);
        }
        if (count($list) >= (int) config('web-rules.max_folders')) {
            throw ValidationException::withMessages(['path' => __('A website can have at most :max protected folders.', ['max' => config('web-rules.max_folders')])]);
        }
        $list[] = ['id' => WebRules::id(), 'path' => $path, 'realm' => $realm,
            'users' => [['name' => $data['username'], 'hash' => WebRules::hash($data['password'])]]];
        $rules->folders = $list;

        return $this->commit($website, $rules, 'folders', 'folder_protected', ['path' => $path],
            __(':path is now protected with a password.', ['path' => $path]));
    }

    public function destroyFolder(Request $request, Website $website, string $id): RedirectResponse
    {
        $this->authorizeSite($request, $website);
        $rules = $this->rules->forSite($website);
        $gone = collect($rules->folderList())->firstWhere('id', $id);
        abort_if($gone === null, 404);
        $rules->folders = array_values(array_filter($rules->folderList(), fn ($f) => $f['id'] !== $id));

        return $this->commit($website, $rules, 'folders', 'folder_unprotected', ['path' => $gone['path']],
            __('Password protection of :path removed.', ['path' => $gone['path']]));
    }

    public function storeUser(Request $request, Website $website, string $id): RedirectResponse
    {
        $this->authorizeSite($request, $website);
        $data = $request->validate([
            'username' => ['required', 'string', 'regex:/^[A-Za-z0-9._@-]{1,64}$/'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
        ], ['username.regex' => __('Use letters, digits and . _ @ - only.')]);
        $rules = $this->rules->forSite($website);
        $folders = $rules->folderList();
        $i = collect($folders)->search(fn ($f) => $f['id'] === $id);
        abort_if($i === false, 404);
        $users = collect($folders[$i]['users'] ?? []);
        $exists = $users->contains('name', $data['username']);
        if (! $exists && $users->count() >= (int) config('web-rules.max_users')) {
            throw ValidationException::withMessages(['username' => __('A folder can have at most :max users.', ['max' => config('web-rules.max_users')])]);
        }
        $users = $users->reject(fn ($u) => $u['name'] === $data['username'])
            ->push(['name' => $data['username'], 'hash' => WebRules::hash($data['password'])])
            ->sortBy('name')->values()->all();
        $folders[$i]['users'] = $users;
        $rules->folders = $folders;

        return $this->commit($website, $rules, 'folders', $exists ? 'folder_password_changed' : 'folder_user_added',
            ['path' => $folders[$i]['path'], 'user' => $data['username']],
            $exists ? __('Password of :user changed.', ['user' => $data['username']]) : __(':user can now open :path.', ['user' => $data['username'], 'path' => $folders[$i]['path']]));
    }

    public function destroyUser(Request $request, Website $website, string $id): RedirectResponse
    {
        $this->authorizeSite($request, $website);
        $name = (string) $request->validate(['username' => ['required', 'string', 'max:64']])['username'];
        $rules = $this->rules->forSite($website);
        $folders = $rules->folderList();
        $i = collect($folders)->search(fn ($f) => $f['id'] === $id);
        abort_if($i === false, 404);
        $users = array_values(array_filter($folders[$i]['users'] ?? [], fn ($u) => $u['name'] !== $name));
        if ($users === []) {
            return $this->back($website, 'folders')->withErrors(['folders' => __('A protected folder needs at least one user. Remove the protection of the folder instead.')]);
        }
        $folders[$i]['users'] = $users;
        $rules->folders = $folders;

        return $this->commit($website, $rules, 'folders', 'folder_user_removed', ['path' => $folders[$i]['path'], 'user' => $name],
            __(':user can no longer open :path.', ['user' => $name, 'path' => $folders[$i]['path']]));
    }

    // ------------------------------------------------------------ error pages

    public function updateErrorPages(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeSite($request, $website);
        $request->validate(['pages' => ['nullable', 'array'], 'pages.*' => ['nullable', 'string', 'max:200']]);
        $pages = [];
        $errors = [];
        foreach (WebRules::ERROR_CODES as $code) {
            $raw = trim((string) $request->input("pages.$code", ''));
            if ($raw === '') {
                continue;
            }
            $path = $this->sitePath($website, $raw);
            if ($path === null || $path === '/') {
                $errors["pages.$code"] = __('Enter a file on this website, such as /errors/:code.html.', ['code' => $code]);

                continue;
            }
            $pages[(string) $code] = $path;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $rules = $this->rules->forSite($website);
        $rules->error_pages = $pages ?: null;

        return $this->commit($website, $rules, 'errors', 'error_pages_saved', ['codes' => array_keys($pages)], __('Error pages saved.'));
    }

    // ------------------------------------------------------------ HTTPS & HSTS

    public function updateHttps(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeSite($request, $website);
        $data = $request->validate([
            'force_https' => ['required', 'boolean'],
            'hsts_enabled' => ['required', 'boolean'],
            'hsts_max_age' => ['required', 'integer', Rule::in(array_keys(WebRules::HSTS_AGES))],
            'hsts_subdomains' => ['nullable', 'boolean'],
            'hsts_preload' => ['nullable', 'boolean'],
        ]);
        $preload = (bool) ($data['hsts_preload'] ?? false);
        if ($preload && (! ($data['hsts_subdomains'] ?? false) || (int) $data['hsts_max_age'] < 31536000)) {
            throw ValidationException::withMessages(['hsts_preload' => __('Preload needs "include subdomains" and a duration of at least one year.')]);
        }
        $rules = $this->rules->forSite($website);
        $rules->fill([
            'force_https' => (bool) $data['force_https'],
            'hsts_enabled' => (bool) $data['hsts_enabled'],
            'hsts_max_age' => (int) $data['hsts_max_age'],
            'hsts_subdomains' => (bool) ($data['hsts_subdomains'] ?? false),
            'hsts_preload' => $preload,
        ]);

        return $this->commit($website, $rules, 'https', 'https_saved', $data, __('HTTPS settings saved.'));
    }

    // ------------------------------------------------------------ IP & country blocking

    public function updateAccess(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeSite($request, $website);
        $data = $request->validate([
            'access_mode' => ['required', Rule::in(['off', 'deny', 'allow'])],
            'networks' => ['nullable', 'string', 'max:20000'],
            'countries_mode' => ['required', Rule::in(['off', 'deny', 'allow'])],
            'countries' => ['nullable', 'array', 'max:250'],
            'countries.*' => ['string', Rule::in(explode(' ', WebRules::COUNTRIES))],
        ]);
        $networks = [];
        foreach (preg_split('/\R/', (string) ($data['networks'] ?? '')) as $n => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$address, $comment] = array_pad(preg_split('/\s+#?\s*/', $line, 2), 2, '');
            $address = strtolower($address);
            if (! self::validNetwork($address)) {
                throw ValidationException::withMessages(['networks' => __('Line :line: :address is not an IP address or range.', ['line' => $n + 1, 'address' => $address])]);
            }
            $networks[$address] = ['address' => $address, 'comment' => mb_substr(trim($comment), 0, 60)];
        }
        if (count($networks) > (int) config('web-rules.max_networks')) {
            throw ValidationException::withMessages(['networks' => __('At most :max addresses.', ['max' => config('web-rules.max_networks')])]);
        }
        $mode = $networks ? $data['access_mode'] : 'off';
        $countries = array_values(array_unique($data['countries'] ?? []));
        sort($countries);
        $cmode = $countries ? $data['countries_mode'] : 'off';
        if ($data['access_mode'] !== 'off' && ! $networks) {
            throw ValidationException::withMessages(['networks' => __('Enter at least one address, or switch address blocking off.')]);
        }
        if ($data['countries_mode'] !== 'off' && ! $countries) {
            throw ValidationException::withMessages(['countries' => __('Choose at least one country, or switch country blocking off.')]);
        }
        if ($cmode !== 'off' && ! ($this->rules->status()['geoip']['available'] ?? false)) {
            throw ValidationException::withMessages(['countries' => __('Country blocking is not available on this server yet: the country database is missing.')]);
        }
        $rules = $this->rules->forSite($website);
        $rules->fill([
            'access_mode' => $mode,
            'access_networks' => array_values($networks) ?: null,
            'countries_mode' => $cmode,
            'countries' => $countries ?: null,
        ]);
        $response = $this->commit($website, $rules, 'access', 'access_saved',
            ['mode' => $mode, 'networks' => count($networks), 'countries_mode' => $cmode, 'countries' => $countries],
            __('Access rules saved.'));

        $ip = (string) $request->ip();
        if ($mode === 'allow' && filter_var($ip, FILTER_VALIDATE_IP) && ! collect($networks)->contains(fn ($n) => self::inNetwork($ip, $n['address']))) {
            $response->with('warning', __('Your current address :ip is not on the list: you cannot open this website yourself right now.', ['ip' => $ip]));
        }
        if ($mode === 'deny' && filter_var($ip, FILTER_VALIDATE_IP) && collect($networks)->contains(fn ($n) => self::inNetwork($ip, $n['address']))) {
            $response->with('warning', __('Your current address :ip is blocked for this website.', ['ip' => $ip]));
        }

        return $response;
    }

    // ------------------------------------------------------------ helpers

    private function commit(Website $website, SiteWebRules $rules, string $tab, string $event, array $props, string $message): RedirectResponse
    {
        try {
            $result = $this->rules->save($rules);
        } catch (AgentException $e) {
            return $this->back($website, $tab)->withInput()->withErrors(['agent' => __('The server did not accept this change, nothing was changed: :message', ['message' => $e->getMessage()])]);
        }
        ActivityLog::record('web_rules.'.$event, $website->domain, $props, $website->customer_id);
        $redirect = $this->back($website, $tab)->with('status', $message);
        if (! empty($result['missing_error_pages'])) {
            $redirect->with('missing_pages', $result['missing_error_pages']);
        }

        return $redirect;
    }

    private function back(Website $website, string $tab): RedirectResponse
    {
        return redirect()->route($this->area.'.web-rules.show', [$website, 'tab' => $tab]);
    }

    /** A path on the website; a full URL of the website itself is accepted too. */
    private function sitePath(Website $website, string $input): ?string
    {
        $input = trim($input);
        if (preg_match('#^https?://#i', $input)) {
            $host = strtolower((string) parse_url($input, PHP_URL_HOST));
            $names = array_map('strtolower', [$website->domain, ...($website->aliases ?? [])]);
            if (! in_array($host, $names, true)) {
                return null;
            }
            $input = (string) (parse_url($input, PHP_URL_PATH) ?: '/');
        }
        $path = WebRules::normalisePath($input);

        return WebRules::validPath($path) ? $path : null;
    }

    private function target(string $to): ?string
    {
        if ($to !== '' && ! str_starts_with($to, '/') && ! preg_match('#^https?://#i', $to)) {
            // "example.org/page" means the other website, "page" a page here.
            $to = preg_match('#^[a-z0-9-]+(\.[a-z0-9-]+)+(/|$)#i', $to) ? 'https://'.$to : '/'.$to;
        }

        return WebRules::validTarget($to) ? $to : null;
    }

    public static function validNetwork(string $value): bool
    {
        [$ip, $bits] = array_pad(explode('/', $value, 2), 2, null);
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if ($bits === null) {
            return true;
        }
        $max = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits >= 1 && (int) $bits <= $max;
    }

    public static function inNetwork(string $ip, string $network): bool
    {
        [$net, $bits] = array_pad(explode('/', $network, 2), 2, null);
        $a = @inet_pton($ip);
        $b = @inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = $bits === null ? strlen($a) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }
}
