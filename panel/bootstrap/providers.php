<?php

use App\Providers\AppServiceProvider;
use App\Providers\DatabasesServiceProvider;
use App\Providers\FtpAccountsServiceProvider;
use App\Providers\PasswordResetServiceProvider;
use App\Providers\TeamServiceProvider;
use App\Providers\WpHardeningServiceProvider;

return [
    AppServiceProvider::class,
    TeamServiceProvider::class,
    DatabasesServiceProvider::class,
    FtpAccountsServiceProvider::class,
    PasswordResetServiceProvider::class,
    WpHardeningServiceProvider::class,
];
