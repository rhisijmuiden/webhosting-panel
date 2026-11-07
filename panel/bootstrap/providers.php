<?php

use App\Providers\AppServiceProvider;
use App\Providers\FtpAccountsServiceProvider;
use App\Providers\TeamServiceProvider;

return [
    AppServiceProvider::class,
    TeamServiceProvider::class,
    FtpAccountsServiceProvider::class,
];
