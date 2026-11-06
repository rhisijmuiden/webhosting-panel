<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who receives the monthly report of a customer. No row = the default (on, the customer's own address).
        Schema::create('report_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->json('extra_emails')->nullable();
            $table->string('changed_by', 10)->nullable(); // customer, admin
            $table->timestamps();
        });

        // Every monthly report that was sent (or failed), so a month is never sent twice.
        Schema::create('report_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7); // YYYY-MM, the month the report is about
            $table->string('kind', 10)->default('schedule'); // schedule, manual
            $table->string('status', 10); // sent, failed
            $table->json('recipients')->nullable();
            $table->string('locale', 8)->nullable();
            $table->json('summary')->nullable(); // the headline numbers, for the admin list
            $table->text('error')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->index(['customer_id', 'period']);
            $table->index(['period', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_sends');
        Schema::dropIfExists('report_preferences');
    }
};
