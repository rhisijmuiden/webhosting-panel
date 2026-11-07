<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Web application firewall choice of one website. No row = server default, no exceptions.
        Schema::create('waf_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('mode', 10)->nullable(); // block, detect, off; null = server default
            $table->json('exceptions')->nullable();
            $table->timestamps();
        });

        // Requests that matched a firewall rule, collected from the server every few minutes.
        Schema::create('waf_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->string('domain');
            $table->string('request_id', 64);
            $table->timestamp('occurred_at')->index();
            $table->string('ip', 45)->nullable();
            $table->string('method', 10)->nullable();
            $table->string('uri', 500)->nullable();
            $table->string('host')->nullable();
            $table->string('user_agent')->nullable();
            $table->unsignedSmallInteger('status')->default(0);
            $table->boolean('blocked')->default(false);
            $table->string('mode', 10)->default('detect');
            $table->unsignedSmallInteger('score')->nullable();
            $table->json('rules');
            $table->string('rule_ids')->default(''); // ",942100,941100," for filtering
            $table->timestamps();
            $table->unique(['domain', 'request_id']);
            $table->index(['website_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waf_events');
        Schema::dropIfExists('waf_sites');
    }
};
