<?php

use Illuminate\Support\Facades\Route;
use Rhis\Monitoring\Http\StatusPageController;

Route::get('/status', [StatusPageController::class, 'show'])->name('status.public');
