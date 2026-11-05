<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hosting_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('crm_id')->nullable()->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('disk_mb')->default(10240);
            $table->unsignedInteger('max_websites')->default(1);
            $table->unsignedInteger('max_mailboxes')->default(10);
            $table->unsignedInteger('max_databases')->default(5);
            $table->unsignedInteger('max_cronjobs')->default(5);
            $table->boolean('allow_ssh')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('crm_id')->nullable()->unique();
            $table->foreignId('hosting_package_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('active'); // active, suspended
            $table->string('locale', 10)->default('en');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('client')->after('password'); // admin, client
            $table->foreignId('customer_id')->nullable()->after('role')->constrained()->cascadeOnDelete();
            $table->string('locale', 10)->default('en')->after('customer_id');
            $table->boolean('is_active')->default(true)->after('locale');
            $table->text('two_factor_secret')->nullable()->after('is_active');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
            $table->timestamp('last_login_at')->nullable()->after('two_factor_confirmed_at');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
        });

        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->json('aliases')->nullable();
            $table->string('profile')->default('php'); // php, wordpress, laravel
            $table->string('php_version', 5)->default('8.4');
            $table->boolean('page_cache')->default(true);
            $table->string('ssl_status')->default('pending'); // active, pending, off
            $table->string('status')->default('provisioning'); // provisioning, active, failed, suspended, removing
            $table->string('system_user')->nullable();
            $table->string('document_root')->nullable();
            $table->unsignedInteger('disk_used_mb')->default(0);
            $table->unsignedInteger('disk_quota_mb')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->boolean('encrypted')->default(false);
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('subject')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('websites');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn(['role', 'locale', 'is_active', 'two_factor_secret', 'two_factor_confirmed_at', 'last_login_at', 'last_login_ip']);
        });
        Schema::dropIfExists('customers');
        Schema::dropIfExists('hosting_packages');
    }
};
