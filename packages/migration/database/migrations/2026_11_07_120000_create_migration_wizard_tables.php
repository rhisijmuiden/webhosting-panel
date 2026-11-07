<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_items', function (Blueprint $table) {
            // Per-domain migration wizard: confirmed steps, website check, DNS choices.
            $table->json('wizard')->nullable()->after('log');
        });

        // The domain's Cloudflare records right before the wizard switched DNS, to undo the switch.
        Schema::create('migration_dns_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('migration_item_id')->constrained('migration_items')->cascadeOnDelete();
            $table->string('domain');
            $table->string('zone_id');
            $table->json('records');
            $table->json('changes');
            $table->string('created_by')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_dns_snapshots');
        Schema::table('migration_items', function (Blueprint $table) {
            $table->dropColumn('wizard');
        });
    }
};
