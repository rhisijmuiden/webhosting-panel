<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plesk-style subscriptions: a hosting package per website instead of per customer.
 * Every existing website gets a subscription with the package its customer had,
 * the website's creation date as setup date, and the traffic alert of the customer.
 * The owner of a subscription is the customer of its website.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('hosting_package_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('active'); // active, suspended
            $table->string('suspended_reason')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('traffic_alert_month', 7)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('status');
            $table->index('expires_on');
        });

        $customers = DB::table('customers')->get(['id', 'hosting_package_id', 'traffic_alert_month'])->keyBy('id');
        foreach (DB::table('websites')->orderBy('id')->get(['id', 'customer_id', 'status', 'last_error', 'created_at']) as $w) {
            $c = $customers[$w->customer_id] ?? null;
            DB::table('subscriptions')->insert([
                'website_id' => $w->id,
                'hosting_package_id' => $c?->hosting_package_id,
                'status' => $w->status === 'suspended' ? 'suspended' : 'active',
                'suspended_reason' => $w->status === 'suspended' ? ($w->last_error ?: null) : null,
                'traffic_alert_month' => $c?->traffic_alert_month,
                'created_at' => $w->created_at ?? now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hosting_package_id');
            $table->dropColumn('traffic_alert_month');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('hosting_package_id')->nullable()->constrained()->nullOnDelete();
            $table->string('traffic_alert_month', 7)->nullable();
        });
        // The package of a customer's first website comes back as the customer's package.
        foreach (DB::table('subscriptions')->join('websites', 'websites.id', '=', 'subscriptions.website_id')
            ->whereNotNull('subscriptions.hosting_package_id')->orderBy('subscriptions.id')
            ->get(['websites.customer_id', 'subscriptions.hosting_package_id']) as $row) {
            DB::table('customers')->where('id', $row->customer_id)->whereNull('hosting_package_id')
                ->update(['hosting_package_id' => $row->hosting_package_id]);
        }
        Schema::dropIfExists('subscriptions');
    }
};
