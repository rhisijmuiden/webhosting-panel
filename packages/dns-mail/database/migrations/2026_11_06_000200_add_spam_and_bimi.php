<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_domains', function (Blueprint $table) {
            // Spam score from which mail goes to the quarantine; null = no quarantine (Junk only).
            $table->unsignedTinyInteger('spam_quarantine_score')->nullable()->default(10);
            $table->boolean('spam_junk')->default(true); // move "X-Spam: Yes" mail to Junk
            $table->string('bimi_logo_url')->nullable();
            $table->string('bimi_vmc_url')->nullable();
        });

        Schema::create('mail_sender_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_domain_id')->constrained()->cascadeOnDelete();
            $table->string('type', 5); // allow, block
            $table->string('value'); // address, or a domain for every sender at it
            $table->timestamps();
            $table->unique(['mail_domain_id', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_sender_rules');
        Schema::table('mail_domains', function (Blueprint $table) {
            $table->dropColumn(['spam_quarantine_score', 'spam_junk', 'bimi_logo_url', 'bimi_vmc_url']);
        });
    }
};
