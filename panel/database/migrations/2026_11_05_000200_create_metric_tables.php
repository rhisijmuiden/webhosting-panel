<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // resolution: minute (kept 48 hours), hour (kept 90 days), day (kept forever)
        Schema::create('metric_samples', function (Blueprint $table) {
            $table->id();
            $table->string('resolution', 8);
            $table->timestamp('taken_at');
            $table->float('cpu_percent');
            $table->float('load1');
            $table->unsignedBigInteger('memory_used');
            $table->unsignedBigInteger('memory_total');
            $table->unsignedBigInteger('swap_used')->default(0);
            $table->unsignedBigInteger('disk_used');
            $table->unsignedBigInteger('disk_total');
            $table->unsignedBigInteger('net_rx_bps');
            $table->unsignedBigInteger('net_tx_bps');
            $table->unique(['resolution', 'taken_at']);
        });

        Schema::create('website_metric_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('resolution', 8);
            $table->timestamp('taken_at');
            $table->float('cpu_percent');
            $table->unsignedBigInteger('memory_bytes');
            $table->unsignedBigInteger('io_read_bps');
            $table->unsignedBigInteger('io_write_bps');
            $table->index(['resolution', 'taken_at']);
            $table->index(['website_id', 'resolution', 'taken_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_metric_samples');
        Schema::dropIfExists('metric_samples');
    }
};
