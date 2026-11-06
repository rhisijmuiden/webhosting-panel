<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->string('prefix', 12);
            $table->json('allowed_ips')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::table('hosting_packages', function (Blueprint $table) {
            $table->json('crm_limits')->nullable();
            $table->json('crm_features')->nullable();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->json('address')->nullable();
        });

        Schema::create('crm_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('external_id', 64)->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hosting_package_id')->constrained();
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->string('primary_domain');
            $table->string('status')->default('provisioning');
            $table->string('php_version', 5)->default('8.4');
            $table->json('limit_overrides')->nullable();
            $table->json('options')->nullable();
            $table->json('suspension')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->timestamp('keep_backup_until')->nullable();
            $table->timestamps();
            $table->index('primary_domain');
        });

        Schema::create('crm_jobs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type');
            $table->string('status')->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('step')->nullable();
            $table->text('error')->nullable();
            $table->ulid('subscription_id')->nullable()->index();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_id')->constrained('crm_api_tokens')->cascadeOnDelete();
            $table->string('key');
            $table->string('fingerprint', 64);
            $table->unsignedSmallInteger('status');
            $table->longText('body');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['token_id', 'key']);
        });

        Schema::create('crm_webhooks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('url', 2000);
            $table->json('events');
            $table->text('secret');
            $table->string('description', 200)->nullable();
            $table->timestamps();
        });

        Schema::create('crm_webhook_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('webhook_id')->index();
            $table->string('event');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('external_id', 64)->nullable();
            $table->string('status')->default('requested');
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->ulid('subscription_id')->nullable();
            $table->string('domain')->nullable();
            $table->string('product_code', 64);
            $table->unsignedInteger('quantity')->default(1);
            $table->json('price_shown');
            $table->text('note')->nullable();
            $table->text('message')->nullable();
            $table->string('checkout_url', 2000)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_orders');
        Schema::dropIfExists('crm_webhook_deliveries');
        Schema::dropIfExists('crm_webhooks');
        Schema::dropIfExists('crm_idempotency_keys');
        Schema::dropIfExists('crm_jobs');
        Schema::dropIfExists('crm_subscriptions');
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('address'));
        Schema::table('hosting_packages', fn (Blueprint $t) => $t->dropColumn(['crm_limits', 'crm_features']));
        Schema::dropIfExists('crm_api_tokens');
    }
};
