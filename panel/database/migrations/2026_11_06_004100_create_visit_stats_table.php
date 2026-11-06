<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Visitors, page views, requests and bytes from the Nginx traffic log.
        // website_id null = the whole server (a visitor of two websites counts once).
        // resolution: hour (kept 400 days) or day (kept forever).
        Schema::create('visit_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('resolution', 8);
            $table->timestamp('period_start');
            $table->unsignedInteger('visitors')->default(0);
            $table->unsignedInteger('pageviews')->default(0);
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('bots')->default(0);
            $table->unsignedBigInteger('bytes')->default(0);
            $table->unique(['website_id', 'resolution', 'period_start']);
            $table->index(['resolution', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_stats');
    }
};
