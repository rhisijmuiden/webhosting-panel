<?php

namespace App\Providers;

use App\Support\Brand;
use App\Support\ToolsCatalog;
use Illuminate\Support\ServiceProvider;

/** Product name, hosting company name and logo: see App\Support\Brand. */
class BrandingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // dns-mail names the mail service after the hosting company (mail client
        // profiles, webmail): it follows the name set under Branding.
        $this->app->booted(fn () => config(['dns-mail.server.brand' => Brand::provider()]));

        ToolsCatalog::add('panel', 'Branding', 'admin.branding.edit', 'Your company name as customers see it');
    }
}
