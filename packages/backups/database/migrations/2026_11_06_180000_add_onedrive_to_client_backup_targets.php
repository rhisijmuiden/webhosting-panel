<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // OneDrive as a customer backup location: no server and user name, but a Microsoft connection.
        Schema::table('client_backup_targets', function (Blueprint $table) {
            $table->string('host')->nullable()->change();
            $table->string('username', 128)->nullable()->change();
            $table->text('onedrive_token')->nullable();      // encrypted, rclone token JSON
            $table->string('drive_id', 200)->nullable();
            $table->string('drive_type', 20)->nullable();    // personal, business, documentLibrary
            $table->string('account')->nullable();           // the Microsoft account it is connected to
        });
    }

    public function down(): void
    {
        Schema::table('client_backup_targets', function (Blueprint $table) {
            $table->dropColumn(['onedrive_token', 'drive_id', 'drive_type', 'account']);
        });
    }
};
