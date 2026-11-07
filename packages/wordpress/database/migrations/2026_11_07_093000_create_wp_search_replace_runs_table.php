<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** WordPress search & replace and change-domain runs: the dry run first, then (maybe) the real change. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wp_search_replace_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_install_id')->constrained('wordpress_installs')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode'); // text | domain
            $table->json('params');
            // previewing | previewed | preview_failed | applying | applied | failed | rolled_back
            $table->string('status')->default('previewing');
            $table->string('preview_job')->nullable();
            $table->string('apply_job')->nullable();
            $table->json('preview')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('previewed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['wordpress_install_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_search_replace_runs');
    }
};
