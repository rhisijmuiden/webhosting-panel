<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->string('domain')->unique();
            $table->string('provider')->default('rhis'); // rhis, microsoft365, external
            $table->boolean('dns_managed')->default(true); // sync records to Cloudflare
            $table->boolean('proxied')->default(true);
            $table->string('cloudflare_zone_id')->nullable();
            $table->text('dkim_public_key')->nullable();
            $table->boolean('server_sends')->default(true);
            $table->string('spf_extra')->nullable();
            $table->string('spf_all', 1)->default('~');
            $table->string('dmarc_policy')->default('quarantine');
            $table->string('dmarc_rua')->nullable();
            $table->string('mta_sts_mode')->nullable()->default('testing');
            $table->string('m365_tenant')->nullable();
            $table->string('m365_mx_host')->nullable();
            $table->string('m365_verification')->nullable();
            $table->string('m365_dkim_selector1')->nullable();
            $table->string('m365_dkim_selector2')->nullable();
            $table->boolean('m365_intune')->default(false);
            $table->string('dns_status')->default('unknown'); // unknown, in_sync, changes, conflict, error, no_zone
            $table->text('dns_error')->nullable();
            $table->timestamp('dns_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_domain_id')->constrained()->cascadeOnDelete();
            $table->string('local_part');
            $table->string('display_name')->nullable();
            $table->text('password_hash');
            $table->unsignedInteger('quota_mb')->default(2048);
            $table->boolean('enabled')->default(true);
            $table->json('send_as')->nullable();
            $table->unsignedBigInteger('used_kb')->nullable();
            $table->timestamps();
            $table->unique(['mail_domain_id', 'local_part']);
        });

        Schema::create('mail_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_domain_id')->constrained()->cascadeOnDelete();
            $table->string('local_part')->default(''); // '' = catch-all
            $table->json('targets');
            $table->timestamps();
            $table->unique(['mail_domain_id', 'local_part']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_aliases');
        Schema::dropIfExists('mailboxes');
        Schema::dropIfExists('mail_domains');
    }
};
