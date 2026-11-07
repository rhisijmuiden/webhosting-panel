<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Valkey object cache per website (see packages/object-cache/README.md). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('object_caches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->boolean('wordpress')->default(true); // configure the WordPress installs of the site
            $table->unsignedInteger('memory_mb')->nullable(); // applied on the server
            $table->string('state', 20)->default('off'); // off | applying | running | stopped | failed
            $table->json('installs')->nullable(); // WordPress installs: [{path, configured, woocommerce, error}]
            $table->text('last_error')->nullable();
            $table->timestamp('flushed_at')->nullable();
            $table->timestamps();
        });

        // Package limit: NULL = server default, 0 = not available on this package.
        Schema::table('hosting_packages', function (Blueprint $table) {
            $table->unsignedInteger('object_cache_mb')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hosting_packages', function (Blueprint $table) {
            $table->dropColumn('object_cache_mb');
        });
        Schema::dropIfExists('object_caches');
    }
};
