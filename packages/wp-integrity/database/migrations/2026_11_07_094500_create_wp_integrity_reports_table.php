<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wp_integrity_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_install_id')->unique()->constrained('wordpress_installs')->cascadeOnDelete();
            $table->string('status')->default('unknown'); // ok | changed | error | unknown
            $table->unsignedInteger('changed_files')->default(0);
            $table->unsignedInteger('malware_files')->default(0);
            $table->unsignedInteger('unverifiable')->default(0);
            $table->json('report')->nullable();
            $table->json('accepted')->nullable(); // "core:wordpress|wp-includes/x.php|<md5>"
            $table->string('job_id', 36)->nullable();
            $table->string('job_kind')->nullable(); // check | restore
            $table->timestamp('job_started_at')->nullable();
            $table->json('last_restore')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_integrity_reports');
    }
};
