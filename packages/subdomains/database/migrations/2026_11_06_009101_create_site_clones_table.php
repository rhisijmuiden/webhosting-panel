<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_clones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_website_id')->nullable()->constrained('websites')->nullOnDelete();
            $table->foreignId('target_website_id')->nullable()->constrained('websites')->nullOnDelete();
            $table->string('source_domain');
            $table->string('target_domain');
            // waiting (new subdomain is being set up), running, succeeded, failed
            $table->string('status', 20)->default('waiting');
            $table->string('job_id', 64)->nullable();
            $table->boolean('replace')->default(false);
            $table->boolean('noindex')->default(true);
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_clones');
    }
};
