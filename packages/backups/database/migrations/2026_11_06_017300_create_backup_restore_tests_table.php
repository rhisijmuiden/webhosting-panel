<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Automatic restore tests of the server backups (whp-backup verify), with their report.
        Schema::create('backup_restore_tests', function (Blueprint $table) {
            $table->id();
            $table->string('trigger', 10)->default('schedule'); // schedule, manual
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target', 40)->nullable();           // null = every enabled location
            $table->string('status', 10)->default('running');   // running, ok, warning, failed, error, busy
            $table->string('job_id', 36)->nullable();
            $table->timestamp('slot_at')->nullable();           // scheduled moment this run belongs to
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('tested')->default(0);
            $table->unsignedInteger('problems')->default(0);
            $table->json('report')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_restore_tests');
    }
};
