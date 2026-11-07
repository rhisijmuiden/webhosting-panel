<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->unique()->constrained()->cascadeOnDelete();
            // letsencrypt, wildcard, custom, imported or none
            $table->string('source', 20)->default('none');
            $table->string('wildcard_of')->nullable();
            $table->string('common_name')->nullable();
            $table->json('names')->nullable();
            $table->string('issuer', 500)->nullable();
            $table->timestamp('not_before')->nullable();
            $table->timestamp('not_after')->nullable();
            $table->string('fingerprint', 120)->nullable();
            $table->boolean('self_signed')->default(false);
            $table->unsignedSmallInteger('chain_length')->default(0);
            // idle, busy (a request runs in the queue) or failed
            $table->string('status', 20)->default('idle');
            $table->string('requested', 20)->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('warned_days')->nullable();
            $table->timestamp('checked_at')->nullable();
            // Certificate signing request made in the panel, with its key (encrypted).
            $table->text('csr')->nullable();
            $table->text('csr_key')->nullable();
            $table->timestamp('csr_created_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_certificates');
    }
};
