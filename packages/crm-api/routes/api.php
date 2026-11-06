<?php

use Illuminate\Support\Facades\Route;
use Rhis\CrmApi\Http\Api\CommerceController;
use Rhis\CrmApi\Http\Api\CustomerController;
use Rhis\CrmApi\Http\Api\PlanController;
use Rhis\CrmApi\Http\Api\SubscriptionController;
use Rhis\CrmApi\Http\Api\SystemController;
use Rhis\CrmApi\Http\Api\WebhookController;

Route::get('ping', [SystemController::class, 'ping']);

Route::get('plans', [PlanController::class, 'index']);
Route::post('plans', [PlanController::class, 'store']);
Route::get('plans/{id}', [PlanController::class, 'show']);
Route::patch('plans/{id}', [PlanController::class, 'update']);
Route::delete('plans/{id}', [PlanController::class, 'destroy']);

Route::get('customers', [CustomerController::class, 'index']);
Route::post('customers', [CustomerController::class, 'store']);
Route::get('customers/{id}', [CustomerController::class, 'show']);
Route::patch('customers/{id}', [CustomerController::class, 'update']);
Route::delete('customers/{id}', [CustomerController::class, 'destroy']);
Route::post('customers/{id}/login-link', [CustomerController::class, 'loginLink']);

Route::get('subscriptions', [SubscriptionController::class, 'index']);
Route::post('subscriptions', [SubscriptionController::class, 'store']);
Route::get('subscriptions/{id}', [SubscriptionController::class, 'show']);
Route::patch('subscriptions/{id}', [SubscriptionController::class, 'update']);
Route::delete('subscriptions/{id}', [SubscriptionController::class, 'destroy']);
Route::post('subscriptions/{id}/suspend', [SubscriptionController::class, 'suspend']);
Route::post('subscriptions/{id}/unsuspend', [SubscriptionController::class, 'unsuspend']);
Route::post('subscriptions/{id}/restore', [SubscriptionController::class, 'restore']);
Route::get('subscriptions/{id}/usage', [SubscriptionController::class, 'usage']);
Route::get('usage', [SubscriptionController::class, 'allUsage']);
Route::get('jobs/{id}', [SubscriptionController::class, 'job']);

Route::get('webhooks', [WebhookController::class, 'index']);
Route::post('webhooks', [WebhookController::class, 'store']);
Route::delete('webhooks/{id}', [WebhookController::class, 'destroy']);
Route::post('webhooks/{id}/test', [WebhookController::class, 'test']);

Route::get('catalog', [CommerceController::class, 'catalog']);
Route::put('catalog', [CommerceController::class, 'putCatalog']);
Route::get('orders', [CommerceController::class, 'orders']);
Route::get('orders/{id}', [CommerceController::class, 'order']);
Route::patch('orders/{id}', [CommerceController::class, 'updateOrder']);
