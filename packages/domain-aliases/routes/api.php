<?php

use Illuminate\Support\Facades\Route;
use Rhis\DomainAliases\Http\Api\DomainAliasApiController as C;

Route::get('domain-aliases', [C::class, 'index']);
Route::get('domain-aliases/{id}', [C::class, 'show']);
Route::patch('domain-aliases/{id}', [C::class, 'update']);
Route::delete('domain-aliases/{id}', [C::class, 'destroy']);
Route::post('domain-aliases/{id}/convert', [C::class, 'convert']);
Route::post('subscriptions/{id}/domain-aliases', [C::class, 'store']);
