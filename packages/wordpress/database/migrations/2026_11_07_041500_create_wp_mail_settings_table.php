<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wp_mail_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_install_id')->unique()->constrained('wordpress_installs')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('from_email')->nullable(); // null = <local part>@<website domain>
            $table->string('from_name', 120)->nullable();
            $table->boolean('force_from')->default(true);
            // Why it is off: null (chosen), 'staging', 'smtp_plugin', 'unavailable'.
            $table->string('reason')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->text('apply_error')->nullable();
            $table->string('last_test_to')->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->text('last_test_error')->nullable();
            $table->string('last_test_queue_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_mail_settings');
    }
};
