<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team members of a customer: extra logins (a web developer, a colleague) with
 * access to some websites and some areas only. See docs/team-members.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('all_websites')->default(false);
            $table->json('areas');
            $table->timestamps();
        });

        Schema::create('team_member_website', function (Blueprint $table) {
            $table->foreignId('team_member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->primary(['team_member_id', 'website_id']);
        });

        Schema::create('team_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('locale', 10)->default('en');
            $table->string('token_hash', 64)->unique();
            $table->boolean('all_websites')->default(false);
            $table->json('website_ids');
            $table->json('areas');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['customer_id', 'email']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('team_require_two_factor')->default(false);
        });

        // The audit log keeps the name of who did it, also after that login is removed.
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('actor')->nullable()->after('user_id');
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'created_at']);
            $table->dropColumn('actor');
        });
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('team_require_two_factor'));
        Schema::dropIfExists('team_invitations');
        Schema::dropIfExists('team_member_website');
        Schema::dropIfExists('team_members');
    }
};
