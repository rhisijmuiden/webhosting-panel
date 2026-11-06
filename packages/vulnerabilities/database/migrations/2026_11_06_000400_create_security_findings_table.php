<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('wordpress_install_id')->nullable()->index();
            $table->string('kind'); // vulnerability | malware
            $table->string('fingerprint', 64);
            $table->string('component_type')->nullable(); // core | plugin | theme | file
            $table->string('component_slug')->nullable();
            $table->string('component_name')->nullable();
            $table->string('component_status')->nullable(); // active | inactive (plugins)
            $table->string('installed_version')->nullable();
            $table->string('fixed_version')->nullable();
            $table->string('severity')->default('unknown'); // critical | high | medium | low | unknown
            $table->decimal('cvss_score', 3, 1)->nullable();
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->string('cve')->nullable();
            $table->json('references')->nullable();
            $table->text('remediation')->nullable();
            $table->string('source')->nullable(); // wordfence | wpvulnerability | clamav | yara
            $table->string('vuln_id')->nullable();
            $table->text('copyright')->nullable();
            $table->string('file_path', 1000)->nullable();
            $table->string('status')->default('open'); // open | resolved | ignored
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->unsignedSmallInteger('notify_count')->default(0);
            $table->foreignId('ignored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ignore_reason')->nullable();
            $table->timestamps();
            $table->unique(['website_id', 'fingerprint']);
            $table->index(['status', 'severity']);
        });

        Schema::create('security_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // alert | reminder | manual
            $table->json('recipients');
            $table->json('finding_ids');
            $table->string('locale', 8)->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();
        });

        Schema::create('security_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->json('extra_emails')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_contacts');
        Schema::dropIfExists('security_notices');
        Schema::dropIfExists('security_findings');
    }
};
