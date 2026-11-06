<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Package limits: every limit can be "unlimited" (NULL), plus data traffic,
 * the resource jail per customer (CPU, memory, disk IO, processes, PHP workers,
 * MariaDB connections) and per-site PHP settings.
 */
return new class extends Migration
{
    private const COUNTS = ['max_websites', 'max_mailboxes', 'max_databases', 'max_cronjobs'];

    public function up(): void
    {
        Schema::table('hosting_packages', function (Blueprint $table) {
            $table->unsignedInteger('disk_mb')->nullable()->default(null)->change();
            foreach (self::COUNTS as $col) {
                $table->unsignedInteger($col)->nullable()->default(null)->change();
            }
            $table->unsignedInteger('traffic_gb_month')->nullable()->after('disk_mb');
            $table->string('traffic_action', 16)->default('notify')->after('traffic_gb_month');
            $table->unsignedInteger('cpu_percent')->nullable();
            $table->unsignedInteger('memory_mb')->nullable();
            $table->unsignedInteger('io_mbps')->nullable();
            $table->unsignedInteger('max_processes')->nullable();
            $table->unsignedInteger('php_workers')->nullable();
            $table->unsignedInteger('db_connections')->nullable();
        });
        // "Unlimited" used to be stored as 0 (disk) or a very high number (CRM API).
        DB::table('hosting_packages')->where('disk_mb', 0)->update(['disk_mb' => null]);
        foreach (self::COUNTS as $col) {
            DB::table('hosting_packages')->where($col, '>=', 1_000_000)->update([$col => null]);
        }

        Schema::table('websites', function (Blueprint $table) {
            $table->json('php_settings')->nullable();
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->string('traffic_alert_month', 7)->nullable();
        });
        Schema::create('website_traffic', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7);
            $table->unsignedBigInteger('bytes')->default(0);
            $table->timestamps();
            $table->unique(['website_id', 'month']);
            $table->index('month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_traffic');
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('traffic_alert_month'));
        Schema::table('websites', fn (Blueprint $t) => $t->dropColumn('php_settings'));
        Schema::table('hosting_packages', function (Blueprint $table) {
            $table->dropColumn(['traffic_gb_month', 'traffic_action', 'cpu_percent', 'memory_mb', 'io_mbps',
                'max_processes', 'php_workers', 'db_connections']);
        });
    }
};
