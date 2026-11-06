<?php

use Illuminate\Support\Facades\Route;
use Rhis\Subdomains\Http\AdminSiteToolsController as C;

Route::post('websites/{website}/subdomains', [C::class, 'storeSubdomain'])->name('subdomains.store');
Route::delete('websites/{website}/subdomains/{subdomain}', [C::class, 'destroySubdomain'])->name('subdomains.destroy');
Route::get('websites/{website}/clone', [C::class, 'createClone'])->name('clones.create');
Route::post('websites/{website}/clone', [C::class, 'storeClone'])->name('clones.store');
Route::get('clones/{clone}', [C::class, 'showClone'])->name('clones.show');
