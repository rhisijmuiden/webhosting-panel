<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mail extras: an autoresponder per mailbox (Sieve vacation) and imports of
 * mailboxes from other providers over IMAP (imapsync).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mail_autoreplies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mailbox_id')->unique()->constrained('mailboxes')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('subject', 200);
            $table->text('body');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedTinyInteger('interval_days')->default(1);
            $table->timestamps();
        });

        Schema::create('mail_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('host');
            $table->unsignedInteger('port');
            $table->string('security', 16);
            $table->string('username');
            $table->text('password')->nullable(); // encrypted; cleared when the run ends
            $table->string('status', 16)->default('queued')->index();
            $table->unsignedInteger('total')->nullable();
            $table->unsignedInteger('done')->default(0);
            $table->unsignedTinyInteger('percent')->default(0);
            $table->unsignedInteger('transferred')->nullable();
            $table->unsignedInteger('skipped')->nullable();
            $table->unsignedInteger('errors')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('error', 1000)->nullable();
            $table->json('log')->nullable();
            $table->unsignedInteger('runs')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_imports');
        Schema::dropIfExists('mail_autoreplies');
    }
};
