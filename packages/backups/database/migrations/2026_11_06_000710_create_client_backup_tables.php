<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backup locations of customers themselves (their own FTP, FTPS or SFTP server).
        Schema::create('client_backup_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('type', 10); // sftp, scp, ftps, ftp
            $table->string('host');
            $table->unsignedInteger('port')->nullable();
            $table->string('username', 128);
            $table->text('password')->nullable();   // encrypted
            $table->text('ssh_key')->nullable();    // encrypted
            $table->text('host_key')->nullable();   // pinned SSH host key
            $table->string('tls', 10)->default('explicit');
            $table->string('path', 300)->default('');
            $table->boolean('allow_plain_ftp')->default(false);
            $table->string('schedule', 10)->default('off'); // off, daily, weekly
            $table->string('schedule_time', 5)->default('03:00');
            $table->string('full_day', 3)->default('Sun');
            $table->json('parts')->nullable();       // files, databases, mail
            $table->json('website_ids')->nullable(); // null = all websites of the customer
            $table->unsignedInteger('keep_full')->default(4);
            $table->timestamp('tested_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // Every customer backup and restore, for history, schedules and the admin overview.
        Schema::create('client_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_backup_target_id')->nullable()->constrained()->nullOnDelete();
            $table->string('domain');
            $table->string('action', 20);  // backup, restore, restore_upload
            $table->string('kind', 12)->nullable(); // full, incremental
            $table->string('trigger', 10)->default('manual'); // manual, schedule
            $table->string('status', 10)->default('running'); // running, succeeded, failed
            $table->string('job_id', 36)->nullable()->index();
            $table->string('file')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->text('message')->nullable();
            $table->string('user')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['client_backup_target_id', 'website_id', 'trigger', 'started_at'], 'client_backups_schedule_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_backups');
        Schema::dropIfExists('client_backup_targets');
    }
};
