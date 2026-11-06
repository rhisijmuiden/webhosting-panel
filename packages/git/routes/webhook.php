<?php

use Illuminate\Support\Facades\Route;
use Rhis\Git\Http\WebhookController;

// Public: GitHub, GitLab, Bitbucket and Gitea call this on every push. Proof is the secret (see the controller).
Route::post('/git/webhook/{uuid}', WebhookController::class)->name('git.webhook');
