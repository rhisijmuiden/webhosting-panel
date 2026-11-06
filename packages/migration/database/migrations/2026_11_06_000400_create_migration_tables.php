<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('plesk');
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(22);
            $table->string('user')->default('root');
            $table->string('status')->default('new'); // new, connected, error
            $table->string('version')->nullable();
            $table->string('hostname')->nullable();
            $table->string('host_key')->nullable();
            $table->text('last_error')->nullable();
            $table->string('inventory_job')->nullable();
            $table->longText('inventory')->nullable();
            $table->timestamp('inventoried_at')->nullable();
            $table->timestamps();
        });

        Schema::create('migration_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('migration_source_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            // queued, preparing, copying, copied, syncing, live, done, failed
            $table->string('status')->default('queued');
            $table->json('options');
            $table->json('source_data');
            $table->string('agent_job')->nullable();
            $table->string('job_kind')->nullable(); // initial, sync, late_mail
            $table->json('result')->nullable();
            $table->json('notes')->nullable();
            $table->text('secrets')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('copied_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('cutover_at')->nullable();
            $table->timestamps();
            $table->unique(['migration_source_id', 'domain']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_items');
        Schema::dropIfExists('migration_sources');
    }
};
