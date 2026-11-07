<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A WordPress staging copy of a live website (made with the clone tool).
        Schema::create('wp_stagings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('live_website_id')->nullable()->constrained('websites')->nullOnDelete();
            $table->foreignId('staging_website_id')->nullable()->constrained('websites')->nullOnDelete();
            $table->string('live_domain');
            $table->string('staging_domain');
            $table->string('path', 200)->default('');
            $table->foreignId('clone_id')->nullable()->constrained('site_clones')->nullOnDelete();
            // copying (the clone runs), ready, failed
            $table->string('status', 20)->default('copying');
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('copied_at')->nullable();
            $table->timestamp('last_push_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        // Every push of a staging copy to live, and every restore of a backup made before one.
        Schema::create('wp_staging_pushes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staging_id')->constrained('wp_stagings')->cascadeOnDelete();
            $table->string('action', 20)->default('push'); // push, restore
            $table->string('status', 20)->default('running'); // running, succeeded, failed
            $table->string('job_id', 64)->nullable();
            $table->json('options')->nullable();
            $table->json('result')->nullable();
            $table->string('backup', 20)->nullable();
            $table->boolean('rolled_back')->default(false);
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_staging_pushes');
        Schema::dropIfExists('wp_stagings');
    }
};
