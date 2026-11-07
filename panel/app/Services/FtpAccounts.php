<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\FtpAccount;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Rhis\CrmApi\Models\Subscription;
use RuntimeException;

/**
 * Extra FTP/SFTP accounts per website. The panel owns the list; every change
 * sends the complete set to the agent (ftp.sync), which writes ProFTPD's
 * virtual users. Only a SHA-512 crypt hash of the password leaves the panel.
 */
class FtpAccounts
{
    /** The part before @domain. */
    public const NAME = '/^[a-z0-9][a-z0-9._-]{0,31}$/';

    /** One folder level: letters, digits, space, dot, dash and underscore. */
    public const SEGMENT = '/^[A-Za-z0-9_][A-Za-z0-9_. -]{0,63}$/';

    public const MIN_PASSWORD = 12;

    public function __construct(private readonly AgentClient $agent) {}

    public static function hash(string $password): string
    {
        return crypt($password, '$6$rounds=100000$'.Str::random(16).'$');
    }

    public static function generatePassword(): string
    {
        // No look-alike characters, always a digit, both cases and a symbol.
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $pw = '';
            for ($i = 0; $i < 18; $i++) {
                $pw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $pw = substr($pw, 0, 6).'-'.substr($pw, 6, 6).'-'.substr($pw, 12);
        } while (! preg_match('/\d/', $pw) || ! preg_match('/[a-z]/', $pw) || ! preg_match('/[A-Z]/', $pw));

        return $pw;
    }

    /** "public/uploads" from what was typed ("/public/uploads/", "public\\uploads"); null when it is not valid. */
    public static function normaliseFolder(?string $folder): ?string
    {
        $folder = str_replace('\\', '/', trim((string) $folder));
        $parts = array_values(array_filter(explode('/', $folder), fn ($p) => $p !== '' && $p !== '.'));
        if (count($parts) > 8) {
            return null;
        }
        foreach ($parts as $p) {
            if ($p === '..' || ! preg_match(self::SEGMENT, $p) || str_ends_with($p, ' ')) {
                return null;
            }
        }

        return implode('/', $parts);
    }

    /** The folder new accounts start in: the website's document root. */
    public static function defaultFolder(Website $website): string
    {
        $docroot = (string) $website->document_root;
        $prefix = '/var/www/vhosts/'.$website->domain.'/';
        if (str_starts_with($docroot, $prefix)) {
            return self::normaliseFolder(substr($docroot, strlen($prefix))) ?? 'public';
        }

        return 'public';
    }

    // ---- limits ------------------------------------------------------------

    /** Extra FTP accounts allowed on this website (null = unlimited). */
    public function limit(Website $website): ?int
    {
        $subscription = class_exists(Subscription::class)
            ? Subscription::where('website_id', $website->parent_id ?: $website->id)->first() : null;
        $override = $subscription?->limit_overrides['ftp_accounts'] ?? null;
        if ($override !== null) {
            return (int) $override;
        }
        $raw = $website->package?->getAttribute('crm_limits');
        $plan = is_array($raw) ? $raw : (is_string($raw) ? (json_decode($raw, true) ?: []) : []);
        if (isset($plan['ftp_accounts'])) {
            return (int) $plan['ftp_accounts'];
        }
        $default = config('ftp-accounts.default_limit');

        return $default === null ? null : (int) $default;
    }

    public function used(Website $website): int
    {
        return FtpAccount::where('website_id', $website->id)->count();
    }

    public function canAdd(Website $website): bool
    {
        $limit = $this->limit($website);

        return $limit === null || $this->used($website) < $limit;
    }

    // ---- changes -----------------------------------------------------------

    /**
     * @param  array{name: string, password: string, folder?: ?string, read_only?: bool, description?: ?string}  $data
     *
     * @throws RuntimeException
     */
    public function create(Website $website, array $data, bool $enforceLimit = true): FtpAccount
    {
        if ($enforceLimit && ! $this->canAdd($website)) {
            throw new RuntimeException(__('Your package allows :count FTP accounts for this website.', ['count' => $this->limit($website)]));
        }
        $login = strtolower($data['name']).'@'.$website->domain;
        if (FtpAccount::withoutGlobalScopes()->where('login', $login)->exists()) {
            throw new RuntimeException(__('There is already an FTP account :login.', ['login' => $login]));
        }
        $account = FtpAccount::create([
            'website_id' => $website->id,
            'login' => $login,
            'password_hash' => self::hash($data['password']),
            'folder' => $data['folder'] ?? self::defaultFolder($website),
            'read_only' => (bool) ($data['read_only'] ?? false),
            'description' => $data['description'] ?? null,
            'password_changed_at' => now(),
        ]);
        ActivityLog::record('ftp_account.created', $login, ['website' => $website->domain, 'folder' => $account->folderLabel(), 'read_only' => $account->read_only], $website->customer_id);
        $this->syncOrWarn();

        return $account;
    }

    /** @param  array{folder?: string, read_only?: bool, enabled?: bool, description?: ?string, password?: ?string}  $data */
    public function update(FtpAccount $account, array $data): FtpAccount
    {
        $fill = array_intersect_key($data, array_flip(['folder', 'read_only', 'enabled', 'description']));
        if (! empty($data['password'])) {
            $fill['password_hash'] = self::hash($data['password']);
            $fill['password_changed_at'] = now();
        }
        $account->fill($fill);
        $changed = array_keys($account->getDirty());
        $account->save();
        if ($changed) {
            $props = ['changed' => array_values(array_diff($changed, ['password_hash', 'password_changed_at', 'updated_at']))];
            if (in_array('password_hash', $changed, true)) {
                $props['password'] = true;
            }
            ActivityLog::record('ftp_account.updated', $account->login, $props, $account->website?->customer_id);
            $this->syncOrWarn();
        }

        return $account;
    }

    public function delete(FtpAccount $account): void
    {
        $login = $account->login;
        $customerId = $account->website?->customer_id;
        $account->delete();
        ActivityLog::record('ftp_account.deleted', $login, [], $customerId);
        $this->syncOrWarn();
    }

    // ---- server ------------------------------------------------------------

    /** The params for ftp.sync: every enabled account of an active website. */
    public function syncParams(): array
    {
        $accounts = FtpAccount::withoutGlobalScopes()->with(['website' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('enabled', true)->orderBy('id')->get()
            ->filter(fn (FtpAccount $a) => $a->website?->status === 'active')
            ->map(fn (FtpAccount $a) => [
                'login' => $a->login,
                'domain' => $a->website->domain,
                'password_hash' => $a->password_hash,
                'folder' => $a->folder,
                'read_only' => $a->read_only,
            ])->values()->all();

        return ['accounts' => $accounts];
    }

    /** Send the complete list to the server. @throws AgentException */
    public function sync(): array
    {
        $result = $this->agent->call('ftp.sync', $this->syncParams(), 60);
        Cache::put('ftp-accounts.skipped', $result['skipped'] ?? [], now()->addDay());
        Cache::forget('ftp-accounts.status');

        return $result;
    }

    /** Sync; when the server cannot be reached the change waits for the next sync (whp:ftp:sync). */
    public function syncOrWarn(): bool
    {
        try {
            $this->sync();
            Cache::forget('ftp-accounts.pending');

            return true;
        } catch (AgentException $e) {
            report($e);
            Cache::forever('ftp-accounts.pending', true);
            session()->flash('warnings', array_merge((array) session('warnings', []), [
                __('The server could not be reached. The change is applied automatically within a few minutes.'),
            ]));

            return false;
        }
    }

    /** Ports and host keys of the FTP server, cached for a few minutes. */
    public function status(): array
    {
        $fallback = ['installed' => false, 'active' => false, 'ftp_port' => 21, 'sftp_port' => 2222, 'tls_required' => true, 'fingerprints' => []];

        return Cache::remember('ftp-accounts.status', now()->addMinutes(5), function () use ($fallback) {
            try {
                return $this->agent->call('ftp.status', [], 20) + $fallback;
            } catch (AgentException) {
                return $fallback + ['unreachable' => true];
            }
        });
    }

    /** Why the server left an account out at the last sync (folder problem, suspended website), keyed by login. */
    public function problems(): array
    {
        return collect(Cache::get('ftp-accounts.skipped', []))->keyBy('login')->all();
    }

    /** The server name for FTP clients: the panel's host name, which the FTPS certificate is for. */
    public function host(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: request()->getHost();
    }
}
