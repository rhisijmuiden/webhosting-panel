<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ftp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('login', 320)->unique(); // <name>@<domain>
            $table->string('password_hash');        // SHA-512 crypt, what ProFTPD checks
            $table->string('folder', 512)->default(''); // below /var/www/vhosts/<domain>, '' = whole website
            $table->boolean('read_only')->default(false);
            $table->boolean('enabled')->default(true);
            $table->string('description', 120)->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ftp_accounts');
    }
};
