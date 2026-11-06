<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Node.js and Python apps of a website (see packages/webapps/README.md). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_apps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('uid', 10)->unique(); // id on the server: whp-webapp-<uid>.service
            $table->string('name', 80);
            $table->string('runtime', 10); // node | python
            $table->string('runtime_version', 10);
            $table->string('app_root', 255)->default('app');
            $table->string('mount_path', 200)->default('/');
            $table->boolean('strip_prefix')->default(false);
            $table->string('start_command', 1000);
            $table->string('build_command', 1000)->nullable();
            $table->text('env')->nullable(); // encrypted JSON
            $table->boolean('deps_on_deploy')->default(true);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('port')->nullable();
            $table->string('last_state', 20)->nullable();
            $table->text('last_error')->nullable();
            $table->string('install_job', 64)->nullable();
            $table->timestamps();
            $table->unique(['website_id', 'mount_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_apps');
    }
};
