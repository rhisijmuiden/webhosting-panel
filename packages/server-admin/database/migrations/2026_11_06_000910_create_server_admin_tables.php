<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->boolean('enabled')->default(true);
            $table->string('frequency', 10)->default('daily'); // hourly, daily, weekly, monthly
            $table->string('time', 5)->default('04:00');        // HH:MM (hourly: only the minutes count)
            $table->unsignedTinyInteger('weekday')->default(0); // weekly, 0 = Sunday
            $table->unsignedTinyInteger('day')->default(1);     // monthly, 1-28
            $table->json('options')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('server_task_runs', function (Blueprint $table) {
            $table->id();
            $table->string('task', 40)->index();     // task key, or upgrade, reboot, window, php_install, ...
            $table->string('trigger', 20)->default('schedule'); // schedule, manual, window, critical, eol
            $table->string('status', 12)->default('running');   // running, succeeded, failed
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('agent_job', 40)->nullable();
            $table->string('apt_run', 32)->nullable();
            $table->string('summary', 500)->nullable();
            $table->json('params')->nullable();
            $table->json('result')->nullable();
            $table->longText('log')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_task_runs');
        Schema::dropIfExists('server_tasks');
    }
};
