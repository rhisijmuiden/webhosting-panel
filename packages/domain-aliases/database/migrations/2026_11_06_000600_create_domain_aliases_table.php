<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('domain')->unique();
            $table->string('mode', 16)->default('serve');      // serve | redirect
            $table->boolean('www')->default(true);
            $table->boolean('mail')->default(true);            // mail for the alias goes to the main domain's mailboxes
            $table->string('ssl_status', 16)->default('pending');
            $table->string('status', 16)->default('provisioning'); // provisioning | active | failed | removing
            $table->text('last_error')->nullable();
            $table->string('upgrade_order_id', 26)->nullable(); // crm_orders.id while an upgrade is ordered
            $table->string('created_by', 16)->default('panel');  // panel | client | crm
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_aliases');
    }
};
