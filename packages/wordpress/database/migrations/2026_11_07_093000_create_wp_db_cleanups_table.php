<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every database cleanup of a WordPress install (agent job wp.dbclean_run).
        Schema::create('wp_db_cleanups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_install_id')->constrained('wordpress_installs')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('running'); // running, succeeded, failed, rolled_back
            $table->string('job_id', 64)->nullable();
            $table->json('items');
            $table->unsignedSmallInteger('keep_revisions')->default(0);
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->bigInteger('size_before')->nullable();
            $table->bigInteger('size_after')->nullable();
            $table->string('snapshot', 20)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_db_cleanups');
    }
};
