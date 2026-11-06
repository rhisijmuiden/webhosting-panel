<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The current state of everything that is watched: one row per website, certificate, disk, service, ...
        Schema::create('monitor_checks', function (Blueprint $t) {
            $t->id();
            $t->string('key', 191)->unique();               // site:12, ssl:12, disk:/, service:nginx, memory, load, mail_queue, component:web
            $t->string('kind', 20)->index();                // website, ssl, disk, inode, service, memory, load, mail_queue, component
            $t->foreignId('website_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('label', 191);
            $t->string('status', 10)->default('ok');        // ok, warning, critical, unknown
            $t->unsignedInteger('fails')->default(0);       // failed checks in a row
            $t->json('value')->nullable();                  // last measured values
            $t->string('detail', 500)->nullable();
            $t->timestamp('since')->nullable();             // the status has been like this since
            $t->timestamp('checked_at')->nullable();
            $t->timestamps();
        });

        // One row per website check (kept a few days, for the response time graph).
        Schema::create('monitor_samples', function (Blueprint $t) {
            $t->id();
            $t->foreignId('website_id')->constrained()->cascadeOnDelete();
            $t->timestamp('checked_at');
            $t->boolean('ok');
            $t->unsignedSmallInteger('code')->default(0);
            $t->unsignedInteger('ms')->nullable();
            $t->index(['website_id', 'checked_at']);
        });

        // Totals per day for websites (site:<id>) and status page components (component:<key>), kept forever.
        Schema::create('monitor_daily', function (Blueprint $t) {
            $t->id();
            $t->string('key', 191);
            $t->foreignId('website_id')->nullable()->constrained()->cascadeOnDelete();
            $t->date('day');
            $t->unsignedInteger('checks')->default(0);
            $t->unsignedInteger('up')->default(0);
            $t->unsignedInteger('down_seconds')->default(0);
            $t->unsignedBigInteger('ms_total')->default(0);
            $t->unsignedInteger('ms_count')->default(0);
            $t->unique(['key', 'day']);
        });

        // A problem from the moment it started until it was solved.
        Schema::create('monitor_incidents', function (Blueprint $t) {
            $t->id();
            $t->string('key', 191)->index();
            $t->string('kind', 20);
            $t->foreignId('website_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title', 255);
            $t->string('severity', 10);                     // warning, critical
            $t->string('detail', 500)->nullable();
            $t->timestamp('started_at');
            $t->timestamp('resolved_at')->nullable()->index();
            $t->timestamp('notified_at')->nullable();
            $t->unsignedInteger('reminders')->default(0);
            $t->timestamps();
        });

        // Where alerts go: mail addresses or a Microsoft Teams channel.
        Schema::create('monitor_channels', function (Blueprint $t) {
            $t->id();
            $t->string('type', 10);                         // mail, teams
            $t->string('name', 120);
            $t->text('target')->nullable();                 // addresses (mail, empty = all administrators) or the webhook URL (encrypted)
            $t->string('min_severity', 10)->default('warning');
            $t->string('locale', 5)->default('en');
            $t->boolean('recoveries')->default(true);
            $t->boolean('enabled')->default(true);
            $t->timestamp('last_sent_at')->nullable();
            $t->string('last_error', 500)->nullable();
            $t->timestamps();
        });

        // Messages the administrator puts on the status page (maintenance, known problem).
        Schema::create('monitor_notices', function (Blueprint $t) {
            $t->id();
            $t->string('title', 191);
            $t->text('body')->nullable();
            $t->string('level', 12)->default('info');      // info, maintenance, degraded, outage
            $t->json('components')->nullable();
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
        });

        // Customers who want a mail when one of their websites goes down.
        Schema::create('monitor_contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $t->boolean('enabled')->default(false);
            $t->json('extra_emails')->nullable();
            $t->timestamps();
        });

        DB::table('monitor_channels')->insert([
            'type' => 'mail', 'name' => 'Administrators', 'target' => null, 'min_severity' => 'warning',
            'recoveries' => true, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        foreach (['monitor_contacts', 'monitor_notices', 'monitor_channels', 'monitor_incidents', 'monitor_daily', 'monitor_samples', 'monitor_checks'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
