<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cron jobs of a website and their runs (see packages/cron/README.md). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cron_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('uid', 10)->unique(); // id on the server: whp-cron-<uid>.timer
            $table->string('name', 80);
            $table->string('type', 10)->default('command'); // command | php | url
            $table->string('command', 2000);
            $table->string('args', 1000)->nullable(); // php: arguments after the script
            $table->string('schedule', 100); // five field cron expression
            $table->unsignedSmallInteger('timeout')->default(60); // minutes
            $table->boolean('enabled')->default(true);
            $table->boolean('notify_failure')->default(true);
            $table->string('notify_email', 255)->nullable(); // empty: the customer's address
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->unsignedInteger('last_duration_ms')->nullable();
            $table->unsignedInteger('failures')->default(0); // failed runs in a row
            $table->timestamp('notified_at')->nullable();
            $table->text('last_error')->nullable(); // the server refused the last change
            $table->timestamps();
        });

        Schema::create('cron_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cron_job_id')->constrained()->cascadeOnDelete();
            $table->string('invocation', 64)->unique();
            $table->string('status', 20); // success | failed | timeout | oom | stopped
            $table->integer('exit_status')->nullable();
            $table->string('signal', 20)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->mediumText('output')->nullable();
            $table->boolean('truncated')->default(false);
            $table->timestamps();
            $table->index(['cron_job_id', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_runs');
        Schema::dropIfExists('cron_jobs');
    }
};
