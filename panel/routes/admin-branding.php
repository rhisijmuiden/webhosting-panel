<?php

use App\Http\Controllers\Admin\BrandingController;
use Illuminate\Support\Facades\Route;

// Tools & settings > Branding: the hosting company name customers see.
Route::get('branding', [BrandingController::class, 'edit'])->name('branding.edit');
Route::put('branding', [BrandingController::class, 'update'])->name('branding.update');
