<?php

declare(strict_types=1);

namespace Rhis\WordPress\SearchReplace;

use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * WordPress search & replace (serialization safe, dry run first, snapshot before the change)
 * and the change-domain wizard. Own routes, views and translations so the toolkit's files stay untouched.
 */
final class SearchReplaceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadJsonTranslationsFrom(__DIR__.'/../../lang/search-replace');

        Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin/wordpress')->name('admin.wordpress.sr.')
            ->group(__DIR__.'/../../routes/search-replace.php');
        Route::middleware(['web', 'auth', 'client'])->prefix('wordpress')->name('client.wordpress.sr.')
            ->group(__DIR__.'/../../routes/search-replace.php');

        // Local development and tests run without the real agent.
        $fake = function ($agent) {
            if ($agent instanceof FakeAgentClient) {
                FakeSearchReplace::register($agent);
            }
        };
        if ($this->app->resolved(AgentClient::class)) {
            $fake($this->app->make(AgentClient::class));
        } else {
            $this->callAfterResolving(AgentClient::class, $fake);
        }
    }
}
