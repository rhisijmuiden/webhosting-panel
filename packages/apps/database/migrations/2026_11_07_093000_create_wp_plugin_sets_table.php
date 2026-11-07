<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** WordPress plugin sets: plugins and themes put on a new WordPress by the app installer. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wp_plugin_sets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('description', 500)->nullable();
            $table->json('plugins');   // [{source, activate, name?}]
            $table->json('themes');    // [{source, activate, name?}]
            $table->boolean('remove_default_content')->default(false);
            $table->boolean('remove_inactive_themes')->default(false);
            $table->boolean('customer_visible')->default(true);
            $table->timestamps();
        });

        // The set a package starts new WordPress sites with (at most one per package).
        Schema::create('wp_plugin_set_packages', function (Blueprint $table) {
            $table->foreignId('hosting_package_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('wp_plugin_set_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('app_installations', function (Blueprint $table) {
            $table->foreignId('plugin_set_id')->nullable()->constrained('wp_plugin_sets')->nullOnDelete();
            $table->string('plugin_set_name', 80)->nullable();
            $table->json('plugin_set_report')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('app_installations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plugin_set_id');
            $table->dropColumn(['plugin_set_name', 'plugin_set_report']);
        });
        Schema::dropIfExists('wp_plugin_set_packages');
        Schema::dropIfExists('wp_plugin_sets');
    }
};
