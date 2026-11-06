<?php

use App\Http\Controllers\Admin;
use Illuminate\Support\Facades\Route;

Route::get('/', Admin\DashboardController::class)->name('dashboard');

Route::resource('customers', Admin\CustomerController::class);
Route::post('customers/{customer}/users', [Admin\CustomerController::class, 'storeUser'])->name('customers.users.store');
Route::post('customers/{customer}/traffic-unsuspend', [Admin\CustomerController::class, 'liftTrafficSuspension'])->name('customers.traffic-unsuspend');
Route::delete('customers/{customer}/users/{user}', [Admin\CustomerController::class, 'destroyUser'])->name('customers.users.destroy');

Route::resource('packages', Admin\PackageController::class)->except('show');
Route::post('packages/{package}/duplicate', [Admin\PackageController::class, 'duplicate'])->name('packages.duplicate');

Route::get('websites', [Admin\WebsiteController::class, 'index'])->name('websites.index');
Route::get('websites/create', [Admin\WebsiteController::class, 'create'])->name('websites.create');
Route::post('websites', [Admin\WebsiteController::class, 'store'])->name('websites.store');
Route::post('websites/bulk', Admin\WebsiteBulkController::class)->name('websites.bulk');
Route::get('websites/{website}', [Admin\WebsiteController::class, 'show'])->name('websites.show');
Route::put('websites/{website}', [Admin\WebsiteController::class, 'update'])->name('websites.update');
Route::put('websites/{website}/php', [Admin\WebsiteController::class, 'php'])->name('websites.php');
Route::post('websites/{website}/ssl', [Admin\WebsiteController::class, 'ssl'])->name('websites.ssl');
Route::delete('websites/{website}', [Admin\WebsiteController::class, 'destroy'])->name('websites.destroy');

Route::get('services', [Admin\ServiceController::class, 'index'])->name('services.index');
Route::post('services/{service}/restart', [Admin\ServiceController::class, 'restart'])->name('services.restart');

Route::get('security', [Admin\SecurityController::class, 'index'])->name('security.index');
Route::post('security/ban', [Admin\SecurityController::class, 'ban'])->name('security.ban');
Route::post('security/unban', [Admin\SecurityController::class, 'unban'])->name('security.unban');

Route::get('firewall', [Admin\FirewallController::class, 'index'])->name('firewall.index');
Route::post('firewall', [Admin\FirewallController::class, 'store'])->name('firewall.store');
Route::delete('firewall/{rule}', [Admin\FirewallController::class, 'destroy'])->name('firewall.destroy');

Route::get('settings/mail', [Admin\MailSettingsController::class, 'edit'])->name('settings.mail.edit');
Route::put('settings/mail', [Admin\MailSettingsController::class, 'update'])->name('settings.mail.update');
Route::post('settings/mail/test', [Admin\MailSettingsController::class, 'test'])->name('settings.mail.test');

Route::get('system', [Admin\SystemController::class, 'index'])->name('system.index');
Route::post('system/check', [Admin\SystemController::class, 'check'])->name('system.check');
Route::post('system/update', [Admin\SystemController::class, 'update'])->name('system.update');
Route::post('system/channel', [Admin\SystemController::class, 'channel'])->name('system.channel');
Route::get('system/jobs/{job}', [Admin\SystemController::class, 'job'])->name('system.job');

Route::get('activity', Admin\ActivityController::class)->name('activity.index');
Route::get('statistics', Admin\StatisticsController::class)->name('statistics.index');

Route::get('php', [Admin\PhpController::class, 'index'])->name('php.index');
Route::put('php', [Admin\PhpController::class, 'update'])->name('php.update');
Route::get('php/extensions/search', [Admin\PhpController::class, 'search'])->name('php.extensions.search');
Route::post('php/extensions', [Admin\PhpController::class, 'install'])->name('php.extensions.install');
Route::delete('php/extensions', [Admin\PhpController::class, 'remove'])->name('php.extensions.remove');
Route::post('php/extensions/toggle', [Admin\PhpController::class, 'toggle'])->name('php.extensions.toggle');

Route::get('tools', [Admin\ToolsController::class, 'index'])->name('tools.index');

// Subscriptions: one website on one hosting package (Plesk-style).
Route::get('subscriptions', [Admin\SubscriptionController::class, 'index'])->name('subscriptions.index');
Route::post('subscriptions/bulk', [Admin\SubscriptionController::class, 'bulk'])->name('subscriptions.bulk');
Route::get('subscriptions/{subscription}/edit', [Admin\SubscriptionController::class, 'edit'])->name('subscriptions.edit');
Route::put('subscriptions/{subscription}', [Admin\SubscriptionController::class, 'update'])->name('subscriptions.update');
