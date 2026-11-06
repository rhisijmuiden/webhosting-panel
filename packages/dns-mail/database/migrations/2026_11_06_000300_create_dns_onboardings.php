<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A customer moving a domain's DNS to the RHIS Cloudflare account (nameserver change at the registrar).
        Schema::create('dns_onboardings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_domain_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('zone_id');
            $table->string('status')->default('pending'); // pending, active, expired
            $table->json('name_servers')->nullable();          // assigned by Cloudflare
            $table->json('original_name_servers')->nullable(); // where the domain pointed when we started
            $table->string('original_registrar')->nullable();
            $table->json('current_name_servers')->nullable();  // last public lookup
            $table->unsignedInteger('imported_records')->default(0);
            $table->unsignedInteger('check_count')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('seen_at')->nullable(); // customer saw the "DNS is live" notice
            $table->timestamps();
        });

        Schema::table('mail_domains', function (Blueprint $table) {
            // Result of the public DNS check for domains whose DNS is hosted elsewhere.
            $table->json('dns_check')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mail_domains', fn (Blueprint $table) => $table->dropColumn('dns_check'));
        Schema::dropIfExists('dns_onboardings');
    }
};
