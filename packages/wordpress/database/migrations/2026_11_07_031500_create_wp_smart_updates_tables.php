<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the scheduled update may do per WordPress install (mode null = the panel default).
        Schema::create('wp_update_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_install_id')->unique()->constrained('wordpress_installs')->cascadeOnDelete();
            $table->string('mode')->nullable(); // all | security | notify | off
            $table->boolean('visual_check')->default(true);
            $table->unsignedTinyInteger('threshold')->nullable();
            $table->json('pages')->nullable();
            $table->string('mail')->default('always'); // always | problems | never
            $table->string('last_window')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('notified_hash', 64)->nullable();
            $table->timestamps();
        });

        // One scheduled (or manual) smart update of an install.
        Schema::create('wp_update_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_install_id')->constrained('wordpress_installs')->cascadeOnDelete();
            $table->string('uid', 40)->unique();
            $table->string('mode');
            $table->string('trigger')->default('schedule'); // schedule | manual
            $table->string('status')->default('running'); // running | updated | rolled_back | failed | notified | up_to_date
            $table->string('reason')->nullable(); // visual | health | error
            $table->string('job')->nullable();
            $table->string('started_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('pending')->nullable();
            $table->json('updated')->nullable();
            $table->json('pages')->nullable();
            $table->decimal('max_score', 5, 2)->nullable();
            $table->unsignedTinyInteger('threshold')->nullable();
            $table->json('health')->nullable();
            $table->json('notes')->nullable();
            $table->text('log')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('mailed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_update_runs');
        Schema::dropIfExists('wp_update_policies');
    }
};
