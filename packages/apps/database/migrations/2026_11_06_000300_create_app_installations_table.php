<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('app', 40);
            $table->string('path', 170)->default('');
            $table->string('status', 20)->default('installing'); // installing, installed, failed, removing
            $table->string('agent_id', 16)->nullable();
            $table->string('job_id', 36)->nullable();
            $table->string('version', 40)->nullable();
            $table->string('url')->nullable();
            $table->string('admin_url')->nullable();
            $table->string('admin_user', 60)->nullable();
            $table->string('database', 64)->nullable();
            $table->string('locale', 5)->default('en');
            $table->text('error')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_installations');
    }
};
