<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wordpress_installs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('path')->default(''); // '' = the docroot itself, or e.g. 'blog'
            $table->string('version')->nullable();
            $table->string('core_update')->nullable();
            $table->json('plugins')->nullable();
            $table->json('themes')->nullable();
            $table->unsignedInteger('outdated_plugins')->default(0);
            $table->unsignedInteger('outdated_themes')->default(0);
            $table->string('cron_mode')->default('wordpress'); // wordpress | system
            $table->unsignedSmallInteger('cron_interval')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_update_at')->nullable();
            $table->string('last_update_status')->nullable(); // succeeded | failed | rolled_back
            $table->json('last_update_summary')->nullable();
            $table->timestamps();
            $table->unique(['website_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_installs');
    }
};
