<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per message Postfix handled (or refused), merged from its log lines.
        Schema::create('mail_log_messages', function (Blueprint $table) {
            $table->id();
            $table->string('queue_id', 20)->nullable()->index(); // null for NOQUEUE rejects
            $table->string('message_id')->nullable()->index();
            $table->timestamp('logged_at')->index();
            $table->timestamp('finished_at')->nullable();
            $table->string('origin', 16)->default('unknown'); // authenticated, website, system, inbound, bounce, unknown
            $table->string('direction', 10)->default('unknown')->index(); // outgoing, incoming, internal, unknown
            $table->string('status', 10)->default('queued')->index(); // queued, sent, deferred, bounced, rejected, expired, mixed
            $table->string('service', 16)->nullable(); // smtp (25), submission, submissions, pickup
            $table->string('sender', 320)->nullable()->index();
            $table->string('sasl_username', 320)->nullable()->index();
            $table->string('client_ip', 64)->nullable()->index();
            $table->string('client_host')->nullable();
            $table->string('system_user', 64)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mail_domain_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mailbox_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('size')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->string('subject')->nullable();
            $table->decimal('spam_score', 6, 2)->nullable();
            $table->string('spam_action', 32)->nullable();
            $table->json('spam_symbols')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'logged_at']);
            $table->index(['direction', 'logged_at']);
        });

        Schema::create('mail_log_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_log_message_id')->constrained()->cascadeOnDelete();
            $table->string('recipient', 320)->index();
            $table->string('orig_recipient', 320)->nullable();
            $table->string('status', 10); // sent, deferred, bounced, rejected, expired
            $table->boolean('local')->default(false);
            $table->string('relay')->nullable();
            $table->string('dsn', 16)->nullable();
            $table->decimal('delay', 10, 2)->nullable();
            $table->string('response', 500)->nullable();
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('logged_at');
            $table->timestamps();
            $table->unique(['mail_log_message_id', 'recipient']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_log_recipients');
        Schema::dropIfExists('mail_log_messages');
    }
};
