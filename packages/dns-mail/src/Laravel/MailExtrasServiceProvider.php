<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Laravel;

use App\Support\Menu;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Rhis\DnsMail\Console\MailImportsCommand;
use Rhis\DnsMail\Http\Controllers\Admin\MailExtrasController as AdminController;
use Rhis\DnsMail\Http\Controllers\Client\MailExtrasController as ClientController;
use Rhis\DnsMail\MailExtras\ImportRunner;
use Rhis\DnsMail\Models\MailAutoreply;
use Rhis\DnsMail\Models\MailImport;

/**
 * Mail extras: autoresponders, catch-all and IMAP import. Registered by
 * DnsMailServiceProvider; kept apart so its routes, views, translations and
 * schedule live in their own files.
 */
final class MailExtrasServiceProvider extends ServiceProvider
{
    private const ROOT = __DIR__ . '/../..';

    public function register(): void
    {
        $this->app->singleton(ImportRunner::class);
    }

    public function boot(): void
    {
        $this->loadJsonTranslationsFrom(self::ROOT . '/lang/mail-extras');

        if ($this->app->runningInConsole()) {
            $this->commands([MailImportsCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('dns-mail:imports')->everyMinute()->withoutOverlapping(10);
        });

        // Data for the parts the mailboxes and import tabs include.
        View::composer('dns-mail::domain.tab-mailboxes', function ($view): void {
            $domain = $view->getData()['domain'];
            $view->with('autoreplies', MailAutoreply::whereIn('mailbox_id', $domain->mailboxes->pluck('id'))->get()->keyBy('mailbox_id'));
        });
        View::composer('dns-mail::domain.tab-import', function ($view): void {
            $domain = $view->getData()['domain'];
            $runner = $this->app->make(ImportRunner::class);
            $imports = MailImport::whereIn('mailbox_id', $domain->mailboxes->pluck('id'))->with('mailbox')->latest('id')->get();
            $imports->where('status', 'running')->each(fn (MailImport $i) => $runner->refresh($i));
            $view->with(['imports' => $imports, 'importAvailable' => $runner->available()]);
        });

        if (!class_exists(Menu::class)) {
            return;
        }
        if (!$this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function (): void {
                $controller = AdminController::class;
                require self::ROOT . '/routes/mail-extras.php';
                Route::get('mail-imports', [AdminController::class, 'index'])->name('mail.imports.index');
            });
            Route::middleware(['web', 'auth', 'client'])->prefix('mail')->name('client.')->group(function (): void {
                $controller = ClientController::class;
                require self::ROOT . '/routes/mail-extras.php';
            });
        }
        Menu::admin()->add('mail-imports', 'Mail imports', 'admin.mail.imports.index', icon: 'migrate', section: 'Hosting', order: 37, activePatterns: ['admin.mail.imports.*']);
    }
}
