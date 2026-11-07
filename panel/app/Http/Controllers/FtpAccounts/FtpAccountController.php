<?php

namespace App\Http\Controllers\FtpAccounts;

use App\Models\FtpAccount;
use App\Models\Website;
use App\Services\FtpAccounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;
use Illuminate\View\View;
use RuntimeException;

/** Shared by the admin and client panel; subclasses scope which websites a user may touch. */
abstract class FtpAccountController
{
    protected string $area = 'admin';

    public function __construct(protected readonly FtpAccounts $ftp) {}

    abstract protected function websites(Request $request): Builder;

    protected function enforceLimit(): bool
    {
        return true;
    }

    protected function canManage(): bool
    {
        return true;
    }

    public function index(Request $request): View
    {
        $websites = $this->websites($request)->orderBy('domain')->get();
        $accounts = FtpAccount::with('website.customer')->whereIn('website_id', $websites->modelKeys())->orderBy('login')->get();

        return view('ftp-accounts.index', [
            'area' => $this->area,
            'accounts' => $accounts,
            'websites' => $websites->where('status', 'active')->values(),
            'manager' => $this->ftp,
            'canManage' => $this->canManage(),
        ]);
    }

    public function store(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeWebsite($request, $website);
        abort_unless($this->canManage(), 403);
        if ($website->status !== 'active') {
            return back()->withErrors(['ftp' => __('FTP accounts can only be added to an active website.')]);
        }
        $request->merge(['name' => strtolower(trim((string) $request->input('name')))]);
        $hasFolder = $request->has('folder');
        if ($hasFolder) {
            $request->merge(['folder' => FtpAccounts::normaliseFolder($request->input('folder')) ?? (string) $request->input('folder')]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:32', 'regex:'.FtpAccounts::NAME],
            'folder' => ['nullable', 'string', 'max:500'],
            'read_only' => ['sometimes', 'boolean'],
            'password' => ['nullable', 'string', 'min:'.FtpAccounts::MIN_PASSWORD, 'max:128'],
            'description' => ['nullable', 'string', 'max:120'],
        ], [
            'name.regex' => __('Use lowercase letters, digits, dots, dashes or underscores, starting with a letter or digit.'),
        ]);
        if ($hasFolder) {
            $this->checkFolder($request, $data['folder'] ?? '');
        }
        $password = ($data['password'] ?? null) ?: FtpAccounts::generatePassword();
        try {
            $account = $this->ftp->create($website, [
                'name' => $data['name'], 'password' => $password,
                'folder' => $hasFolder ? (FtpAccounts::normaliseFolder($data['folder'] ?? '') ?? '') : null,
                'read_only' => $request->boolean('read_only'),
                'description' => $data['description'] ?? null,
            ], $this->enforceLimit());
        } catch (RuntimeException $e) {
            return back()->withInput($request->except('password'))->withErrors(['name' => $e->getMessage()]);
        }

        return $this->done($account, __('FTP account :login created.', ['login' => $account->login]), ($data['password'] ?? null) ? null : $password);
    }

    public function update(Request $request, FtpAccount $account): RedirectResponse
    {
        $this->authorizeAccount($request, $account);
        abort_unless($this->canManage(), 403);
        if ($request->has('folder')) {
            $request->merge(['folder' => FtpAccounts::normaliseFolder($request->input('folder')) ?? (string) $request->input('folder')]);
        }
        $data = $request->validate([
            'folder' => ['sometimes', 'nullable', 'string', 'max:500'],
            'read_only' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        if (array_key_exists('folder', $data)) {
            $this->checkFolder($request, $data['folder'] ?? '');
            $data['folder'] = FtpAccounts::normaliseFolder($data['folder'] ?? '') ?? '';
        }
        $this->ftp->update($account, $data);

        return back()->with('status', __('FTP account :login saved.', ['login' => $account->login]));
    }

    public function password(Request $request, FtpAccount $account): RedirectResponse
    {
        $this->authorizeAccount($request, $account);
        abort_unless($this->canManage(), 403);
        $data = $request->validate([
            'password' => ['nullable', 'string', 'min:'.FtpAccounts::MIN_PASSWORD, 'max:128'],
        ]);
        $password = ($data['password'] ?? null) ?: FtpAccounts::generatePassword();
        $this->ftp->update($account, ['password' => $password]);

        return $this->done($account, __('New password set for :login.', ['login' => $account->login]), ($data['password'] ?? null) ? null : $password);
    }

    public function destroy(Request $request, FtpAccount $account): RedirectResponse
    {
        $this->authorizeAccount($request, $account);
        abort_unless($this->canManage(), 403);
        $this->ftp->delete($account);

        return back()->with('status', __('FTP account :login removed. Open connections are closed.', ['login' => $account->login]));
    }

    /** A generated password is shown once, right after it was made. */
    private function done(FtpAccount $account, string $message, ?string $generated): RedirectResponse
    {
        $redirect = back()->with('status', $message);
        if ($generated !== null) {
            $redirect->with('ftp_password', ['login' => $account->login, 'password' => $generated]);
        }

        return $redirect;
    }

    private function checkFolder(Request $request, ?string $folder): void
    {
        if (FtpAccounts::normaliseFolder($folder) === null) {
            $v = validator([], []);
            $v->after(fn (Validator $v) => $v->errors()->add('folder', __('Use a folder inside the website, for example public/uploads. ".." is not allowed.')));
            $v->validate();
        }
    }

    protected function authorizeWebsite(Request $request, Website $website): void
    {
        abort_unless($this->websites($request)->whereKey($website->id)->exists(), 404);
    }

    protected function authorizeAccount(Request $request, FtpAccount $account): void
    {
        abort_unless($this->websites($request)->whereKey($account->website_id)->exists(), 404);
    }
}
