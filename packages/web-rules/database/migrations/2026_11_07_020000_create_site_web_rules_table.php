<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_web_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('redirects')->nullable();      // [{id, from, to, code, prefix, keep_query}]
            $table->json('folders')->nullable();        // [{id, path, realm, users: [{name, hash}]}]
            $table->json('error_pages')->nullable();    // {"404": "/errors/404.html"}
            $table->boolean('force_https')->default(true);
            $table->boolean('hsts_enabled')->default(true);
            $table->unsignedInteger('hsts_max_age')->default(31536000);
            $table->boolean('hsts_subdomains')->default(false);
            $table->boolean('hsts_preload')->default(false);
            $table->string('access_mode', 8)->default('off');     // off | deny | allow
            $table->json('access_networks')->nullable();          // [{address, comment}]
            $table->string('countries_mode', 8)->default('off');  // off | deny | allow
            $table->json('countries')->nullable();                // ["CN", "RU"]
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_web_rules');
    }
};
