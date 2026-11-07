<?php

use App\Providers\AppServiceProvider;
use App\Providers\DatabasesServiceProvider;
use App\Providers\FtpAccountsServiceProvider;
use App\Providers\TeamServiceProvider;
use App\Providers\WpImportServiceProvider;

return [
    AppServiceProvider::class,
    TeamServiceProvider::class,
    DatabasesServiceProvider::class,
    FtpAccountsServiceProvider::class,
    WpImportServiceProvider::class,
];
