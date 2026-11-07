<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** WordPress import wizard: one row per import of a website zip + database export. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wp_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // upload -> checking -> checked -> running -> done | failed
            $table->string('status', 20)->default('upload');
            $table->json('files')->nullable();
            $table->json('report')->nullable();
            $table->json('options')->nullable();
            $table->json('result')->nullable();
            $table->json('log')->nullable();
            $table->string('job_id', 64)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['website_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_imports');
    }
};
