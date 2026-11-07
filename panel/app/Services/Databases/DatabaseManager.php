<?php

namespace App\Services\Databases;

use App\Models\ActivityLog;
use App\Models\DatabaseUser;
use App\Models\SiteDatabase;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Team\WebsiteScope;
use Illuminate\Support\Facades\Log;

/**
 * Databases and database users of websites (whp-agent db.*). The agent is the
 * source of truth for what exists in MariaDB; site_databases and
 * site_database_users mirror it for listings, limits and backups.
 * Passwords are never stored: they are shown once after create or reset.
 */
class DatabaseManager
{
    public function __construct(private readonly AgentClient $agent) {}

    /** The start of every name a customer picks, as the agent makes it (bakkerij_nl_). */
    public static function prefix(Website $website): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($website->domain)), '_');

        return trim(substr($base, 0, 20), '_').'_';
    }

    /** The websites that share a subscription with this one (main website and its subdomains). */
    public static function subscriptionWebsiteIds(Website $website): array
    {
        $main = $website->parent_id ?: $website->id;

        return Website::withoutGlobalScope(WebsiteScope::class)
            ->where('id', $main)->orWhere('parent_id', $main)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Most databases the package allows (null = no limit). */
    public static function limit(Website $website): ?int
    {
        $max = $website->package?->max_databases;

        return $max === null ? null : (int) $max;
    }

    /** Databases of the whole subscription (the package limit counts them together). */
    public static function used(Website $website): int
    {
        return SiteDatabase::whereIn('website_id', self::subscriptionWebsiteIds($website))->count();
    }

    public static function left(Website $website): ?int
    {
        $max = self::limit($website);

        return $max === null ? null : max(0, $max - self::used($website));
    }

    /**
     * Bring the panel in line with the server for one website and return what
     * the agent knows: databases by name (size, tables, users) and extra users.
     * Databases the panel knows but the agent does not (migrated ones) are adopted.
     *
     * @return array{databases: array<string, array>, users: array<string, array>, prefix: ?string}|null null when the agent did not answer
     */
    public function sync(Website $website): ?array
    {
        try {
            $live = $this->agent->call('db.list', ['domain' => $website->domain]);
            $known = collect($live['databases'] ?? [])->keyBy('name');
            $adopted = false;
            foreach ($website->databases()->get() as $db) {
                if (! $known->has($db->name)) {
                    try {
                        $this->agent->call('db.adopt', ['domain' => $website->domain, 'name' => $db->name, 'user' => $db->users[0] ?? $db->name]);
                        $adopted = true;
                    } catch (AgentException $e) {
                        Log::info('db.adopt failed', ['database' => $db->name, 'error' => $e->getMessage()]);
                    }
                }
            }
            if ($adopted) {
                $live = $this->agent->call('db.list', ['domain' => $website->domain]);
                $known = collect($live['databases'] ?? [])->keyBy('name');
            }
        } catch (AgentException $e) {
            Log::warning('db.list failed', ['domain' => $website->domain, 'error' => $e->getMessage()]);

            return null;
        }

        foreach ($known as $name => $row) {
            $users = collect($row['users'] ?? [])->where('main', true)->pluck('user')->all() ?: [$row['user'] ?? $name];
            $db = SiteDatabase::firstOrNew(['name' => $name]);
            if (! $db->exists || $db->website_id === $website->id) {
                $db->fill(['website_id' => $website->id, 'users' => $users])->save();
            }
        }
        $users = collect($live['users'] ?? [])->keyBy('user');
        foreach ($users as $name => $row) {
            DatabaseUser::updateOrCreate(['name' => $name], [
                'website_id' => $website->id, 'databases' => $row['databases'] ?? [], 'readonly' => (bool) ($row['readonly'] ?? false),
            ]);
        }
        DatabaseUser::where('website_id', $website->id)->whereNotIn('name', $users->keys()->all() ?: [''])->delete();

        return ['databases' => $known->all(), 'users' => $users->all(), 'prefix' => $live['prefix'] ?? null];
    }

    /** Sizes of every database on the server, by name (null when the agent did not answer). */
    public function sizes(): ?array
    {
        try {
            return collect($this->agent->call('db.list')['databases'] ?? [])
                ->mapWithKeys(fn ($d) => [$d['name'] => ['size_bytes' => (int) ($d['size_bytes'] ?? 0), 'tables' => $d['tables'] ?? null]])->all();
        } catch (AgentException) {
            return null;
        }
    }

    /**
     * @return array{name: string, user: string, password: string, host: string}
     *
     * @throws DatabaseException|AgentException
     */
    public function create(Website $website, string $suffix, ?string $password = null): array
    {
        $left = self::left($website);
        if ($left !== null && $left <= 0) {
            throw new DatabaseException(self::limit($website) === 0
                ? __('The package of this website does not include databases.')
                : __('This website already has the maximum number of databases (:max).', ['max' => self::limit($website)]));
        }
        $out = $this->agent->call('db.create', array_filter([
            'domain' => $website->domain, 'name' => $suffix, 'password' => $password,
        ], fn ($v) => $v !== null && $v !== ''));
        SiteDatabase::updateOrCreate(['name' => $out['name']], ['website_id' => $website->id, 'users' => [$out['user']], 'origin' => 'panel']);
        ActivityLog::record('database.created', $out['name'], ['domain' => $website->domain], $website->customer_id);

        return $out;
    }

    public function drop(SiteDatabase $database): void
    {
        $website = $database->website;
        try {
            $out = $this->agent->call('db.drop', ['domain' => $website->domain, 'name' => $database->name]);
        } catch (AgentException $e) {
            // Already gone on the server: forget it here too.
            if ($e->errorCode !== 'not_found') {
                throw $e;
            }
            $out = ['dropped_users' => []];
        }
        DatabaseUser::whereIn('name', $out['dropped_users'] ?? [])->delete();
        foreach (DatabaseUser::where('website_id', $website->id)->get() as $user) {
            if (in_array($database->name, $user->databases ?? [], true)) {
                $rest = array_values(array_diff($user->databases, [$database->name]));
                $rest ? $user->update(['databases' => $rest]) : $user->delete();
            }
        }
        $database->delete();
        ActivityLog::record('database.dropped', $database->name, ['domain' => $website->domain], $website->customer_id);
    }

    /** @return array{user: string, password: string, databases: array, readonly: bool} */
    public function createUser(Website $website, string $suffix, array $databases, bool $readonly, ?string $password = null): array
    {
        $out = $this->agent->call('db.user_create', array_filter([
            'domain' => $website->domain, 'name' => $suffix, 'databases' => array_values($databases),
            'readonly' => $readonly, 'password' => $password,
        ], fn ($v) => $v !== null && $v !== ''));
        DatabaseUser::updateOrCreate(['name' => $out['user']], [
            'website_id' => $website->id, 'databases' => $out['databases'], 'readonly' => (bool) $out['readonly'],
        ]);
        ActivityLog::record('database.user_created', $out['user'], ['domain' => $website->domain, 'databases' => $out['databases'], 'readonly' => $readonly], $website->customer_id);

        return $out;
    }

    public function updateUser(DatabaseUser $user, array $databases, bool $readonly): void
    {
        $out = $this->agent->call('db.user_update', [
            'domain' => $user->website->domain, 'user' => $user->name, 'databases' => array_values($databases), 'readonly' => $readonly,
        ]);
        $user->update(['databases' => $out['databases'], 'readonly' => (bool) $out['readonly']]);
        ActivityLog::record('database.user_updated', $user->name, ['databases' => $out['databases'], 'readonly' => $readonly], $user->website->customer_id);
    }

    public function dropUser(DatabaseUser $user): void
    {
        try {
            $this->agent->call('db.user_drop', ['domain' => $user->website->domain, 'user' => $user->name]);
        } catch (AgentException $e) {
            if ($e->errorCode !== 'not_found') {
                throw $e;
            }
        }
        $user->delete();
        ActivityLog::record('database.user_dropped', $user->name, [], $user->website->customer_id);
    }

    /** New password for a main or extra user of the website. @return array{user: string, password: string} */
    public function resetPassword(Website $website, string $user, ?string $password = null): array
    {
        $out = $this->agent->call('db.password', array_filter([
            'domain' => $website->domain, 'user' => $user, 'password' => $password,
        ], fn ($v) => $v !== null && $v !== ''));
        ActivityLog::record('database.password_reset', $user, ['domain' => $website->domain], $website->customer_id);

        return $out;
    }

    /** The address that logs the browser in to phpMyAdmin for this database, once. */
    public function phpMyAdminUrl(SiteDatabase $database, string $locale): string
    {
        $out = $this->agent->call('db.sso', [
            'domain' => $database->website->domain, 'name' => $database->name, 'lang' => in_array($locale, ['en', 'nl'], true) ? $locale : 'en',
        ]);
        ActivityLog::record('database.phpmyadmin', $database->name, [], $database->website->customer_id);

        return url($out['path'] ?? '/phpmyadmin/whp-sso.php').'?token='.$out['token'];
    }

    /** The website is being removed: drop its databases and users on the server. */
    public function dropAllFor(Website $website): void
    {
        foreach (SiteDatabase::where('website_id', $website->id)->get() as $db) {
            try {
                $this->drop($db->setRelation('website', $website));
            } catch (AgentException $e) {
                Log::warning('db.drop failed while removing website', ['database' => $db->name, 'error' => $e->getMessage()]);
            }
        }
        foreach (DatabaseUser::where('website_id', $website->id)->get() as $user) {
            try {
                $this->dropUser($user->setRelation('website', $website));
            } catch (AgentException $e) {
                Log::warning('db.user_drop failed while removing website', ['user' => $user->name, 'error' => $e->getMessage()]);
            }
        }
    }
}
