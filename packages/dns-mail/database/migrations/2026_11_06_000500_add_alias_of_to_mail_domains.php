<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mail domain aliases: mail for user@alias.example is delivered to
 * user@main.example for every mailbox and alias of the main domain.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('mail_domains', function (Blueprint $table): void {
            $table->foreignId('alias_of_id')->nullable()->after('website_id')->constrained('mail_domains')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mail_domains', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('alias_of_id');
        });
    }
};
