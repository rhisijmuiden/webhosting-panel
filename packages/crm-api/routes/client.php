<?php

use Illuminate\Support\Facades\Route;
use Rhis\CrmApi\Http\Client\ShopController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::post('/orders', [C::class, 'store'])->name('orders.store');
Route::delete('/orders/{order}', [C::class, 'cancel'])->name('orders.cancel');
