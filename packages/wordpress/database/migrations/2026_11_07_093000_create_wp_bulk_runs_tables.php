<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wp_bulk_runs', function (Blueprint $t) {
            $t->id();
            $t->string('action', 64);
            $t->json('options')->nullable();
            $t->string('summary', 500)->nullable();
            $t->string('status', 16)->default('running')->index(); // running, paused, cancelled, finished
            $t->unsignedTinyInteger('concurrency')->default(3);
            $t->unsignedSmallInteger('stop_after_failures')->default(0);
            $t->unsignedInteger('total')->default(0);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('user_name')->nullable();
            $t->string('stop_reason', 500)->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });

        Schema::create('wp_bulk_run_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('run_id')->constrained('wp_bulk_runs')->cascadeOnDelete();
            $t->foreignId('wordpress_install_id')->nullable()->constrained('wordpress_installs')->nullOnDelete();
            $t->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $t->string('label');
            $t->string('customer')->nullable();
            $t->unsignedInteger('position')->default(0);
            $t->string('status', 16)->default('pending'); // pending, running, succeeded, failed, skipped, cancelled
            $t->text('message')->nullable();
            $t->json('details')->nullable();
            $t->string('agent_job', 64)->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_bulk_run_items');
        Schema::dropIfExists('wp_bulk_runs');
    }
};
