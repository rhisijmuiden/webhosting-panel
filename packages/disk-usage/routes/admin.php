<?php

use Illuminate\Support\Facades\Route;
use Rhis\DiskUsage\Http\AdminDiskUsageController as C;

Route::get('disk-usage', [C::class, 'index'])->name('disk-usage.index');
Route::get('disk-usage/{website}', [C::class, 'show'])->whereNumber('website')->name('disk-usage.show');
Route::post('disk-usage/{website}/scan', [C::class, 'scan'])->whereNumber('website')->name('disk-usage.scan');
