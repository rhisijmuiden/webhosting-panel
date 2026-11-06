<?php

namespace App\Providers;

use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\Team\TeamAccess;
use App\Services\Team\TeamAudit;
use App\Services\Team\WebsiteScope;
use App\Support\Menu;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Team members of customers, their access and the audit log (docs/team-members.md). */
class TeamServiceProvider extends ServiceProvider
{
    /** Models with a website_id that a limited team member only sees for their own websites. */
    public const SCOPED_MODELS = [
        \App\Models\Subscription::class,
        \App\Models\SiteDatabase::class,
        \Rhis\Apps\Models\AppInstallation::class,
        \Rhis\Backups\Models\ClientBackup::class,
        \Rhis\DnsMail\Models\MailDomain::class,
        \Rhis\DnsMail\Models\MailLogMessage::class,
        \Rhis\DomainAliases\Models\DomainAlias::class,
        \Rhis\Git\Models\GitRepository::class,
        \Rhis\Security\Models\SiteProtection::class,
        \Rhis\Subdomains\Models\SiteClone::class,
        \Rhis\Vulnerabilities\Models\Finding::class,
        \Rhis\WordPress\Models\WordPressInstall::class,
    ];

    public function register(): void
    {
        $this->app->singleton(TeamAccess::class);
        $this->app->singleton(TeamAudit::class);
    }

    public function boot(): void
    {
        Website::addGlobalScope(new WebsiteScope);
        foreach (self::SCOPED_MODELS as $model) {
            if (class_exists($model)) {
                $model::addGlobalScope(new WebsiteScope);
            }
        }

        ActivityLog::creating(function (ActivityLog $log) {
            if ($log->actor === null && $log->user_id && ($user = $log->user)) {
                $log->actor = $user->name.' <'.$user->email.'>';
            }
        });
        ActivityLog::created(fn () => TeamAudit::written());
        ActivityLog::labels([
            'client.change' => fn (ActivityLog $log) => TeamAudit::describe($log),
            'team.invited' => 'Team member invited',
            'team.invite_resent' => 'Invitation sent again',
            'team.invite_revoked' => 'Invitation withdrawn',
            'team.joined' => 'Joined the team',
            'team.updated' => 'Team member access changed',
            'team.removed' => 'Team member removed',
            'team.settings_changed' => 'Team settings changed',
        ]);

        RateLimiter::for('team-invitation', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'client'])->name('client.')->group(base_path('routes/client-team.php'));
            Route::middleware(['web', 'throttle:team-invitation'])->prefix('team/join')->name('team.invitation.')
                ->group(base_path('routes/team-invitation.php'));
        }

        $owner = fn () => app(TeamAccess::class)->isOwner();
        Menu::client()
            ->add('team', 'Team', 'client.team.index', icon: 'customers', section: 'Account', order: 410,
                activePatterns: ['client.team.index', 'client.team.invite', 'client.team.members.*'], visible: $owner)
            ->add('audit-log', 'Activity log', 'client.team.activity', icon: 'list', section: 'Account', order: 420,
                activePatterns: ['client.team.activity'], visible: $owner)
            ->filter(fn (array $item) => app(TeamAccess::class)->allowsRoute($item['route']));
    }
}
