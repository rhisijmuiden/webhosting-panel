<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('git_repositories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // id on the server (git/<uuid>/) and in the webhook URL
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('url', 400);
            $table->string('provider')->default('other'); // github | gitlab | bitbucket | gitea | other
            $table->string('branch')->default('main');
            $table->string('deploy_path'); // relative to the site root: public, app, …
            $table->string('mode')->default('direct'); // direct | releases
            $table->boolean('auto_deploy')->default(true);
            $table->json('steps')->nullable(); // [{type, command?}]
            $table->json('shared')->nullable(); // ["storage/", ".env"]
            $table->unsignedSmallInteger('keep_releases')->default(5);
            $table->text('webhook_secret'); // encrypted
            $table->text('public_key')->nullable();
            $table->string('fingerprint')->nullable();
            $table->json('latest_commit')->nullable(); // newest commit on the branch we know of
            $table->json('deployed_commit')->nullable();
            $table->string('current_release')->nullable();
            $table->string('status')->default('new'); // new | ok | failed | deploying
            $table->text('last_error')->nullable();
            $table->timestamp('last_deployed_at')->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->timestamps();
        });

        Schema::create('git_deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('git_repository_id')->constrained()->cascadeOnDelete();
            $table->string('trigger'); // manual | webhook | rollback
            $table->string('user')->nullable();
            $table->string('job_id')->nullable()->index();
            $table->string('status')->default('queued'); // queued | running | succeeded | failed
            $table->string('commit')->nullable(); // full hash to deploy (redeploy), null = newest
            $table->json('result')->nullable(); // {commit: {...}, release, steps, seconds}
            $table->json('log')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['git_repository_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('git_deployments');
        Schema::dropIfExists('git_repositories');
    }
};
