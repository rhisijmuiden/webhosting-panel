<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('whp:metrics:collect')->everyMinute()->withoutOverlapping();
Schedule::command('whp:metrics:rollup')->hourlyAt(2)->withoutOverlapping();
Schedule::command('whp:traffic:collect')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('whp:visits:collect')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('whp:subscriptions:expire')->dailyAt('00:10')->withoutOverlapping();
