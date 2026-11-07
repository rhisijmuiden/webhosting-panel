<?php

use App\Providers\AppServiceProvider;
use App\Providers\DatabasesServiceProvider;
use App\Providers\TeamServiceProvider;

return [
    AppServiceProvider::class,
    TeamServiceProvider::class,
    DatabasesServiceProvider::class,
];
