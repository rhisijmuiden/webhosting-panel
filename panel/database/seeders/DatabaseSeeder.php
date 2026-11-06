<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Seeder;

/** Demo data for local development: php artisan migrate --seed (with WHP_AGENT_DRIVER=fake). */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(['email' => 'admin@example.test'], [
            'name' => 'Admin', 'password' => 'password', 'role' => User::ROLE_ADMIN, 'locale' => 'en',
        ]);

        $start = HostingPackage::create(['name' => 'Start', 'disk_mb' => 5120, 'max_websites' => 1, 'max_mailboxes' => 5, 'max_databases' => 2, 'max_cronjobs' => 2]);
        $business = HostingPackage::create(['name' => 'Business', 'disk_mb' => 10240, 'traffic_gb_month' => 100, 'max_websites' => 3, 'max_mailboxes' => 10, 'max_databases' => 5, 'max_cronjobs' => 10,
            'cpu_percent' => 200, 'memory_mb' => 2048, 'io_mbps' => 100, 'php_workers' => 10]);
        $pro = HostingPackage::create(['name' => 'Pro', 'disk_mb' => 25600, 'max_websites' => 10, 'max_mailboxes' => 50, 'max_databases' => 20, 'max_cronjobs' => 25, 'allow_ssh' => true]);

        // Reinder's default: everything unlimited, customers share the server fairly.
        HostingPackage::create(['name' => 'Unlimited', 'description' => 'No limits, fair share of the server.', 'allow_ssh' => true]);

        $customers = [
            ['Bakkerij de Vries', 'Jan de Vries', 'jan@bakkerijdevries.example', $business, 'nl', [['bakkerijdevries.example', 'wordpress', '8.4', 3100]]],
            ['Installatiebedrijf Smit', 'Petra Smit', 'petra@smit-installatie.example', $pro, 'nl', [['smit-installatie.example', 'laravel', '8.5', 7800], ['smit-service.example', 'php', '8.4', 420]]],
            ['Kapsalon Lotte', 'Lotte Bakker', 'info@kapsalonlotte.example', $start, 'en', [['kapsalonlotte.example', 'wordpress', '8.3', 600]]],
        ];
        foreach ($customers as $i => [$company, $name, $email, $package, $locale, $sites]) {
            $customer = Customer::create(['company' => $company, 'name' => $name, 'email' => $email, 'hosting_package_id' => $package->id, 'locale' => $locale, 'crm_id' => 'CRM-'.(1001 + $i)]);
            foreach ($sites as [$domain, $profile, $php, $mb]) {
                Website::create([
                    'customer_id' => $customer->id, 'domain' => $domain, 'aliases' => ['www.'.$domain], 'profile' => $profile, 'php_version' => $php,
                    'page_cache' => $profile !== 'laravel', 'ssl_status' => 'active', 'status' => 'active', 'system_user' => 'site'.($i + 1),
                    'document_root' => $profile === 'laravel' ? 'app/public' : 'public', 'disk_used_mb' => $mb, 'disk_quota_mb' => $package->disk_mb,
                ]);
            }
            User::create(['name' => $name, 'email' => $email, 'password' => 'password', 'role' => User::ROLE_CLIENT, 'customer_id' => $customer->id, 'locale' => $locale]);
        }

        $this->call(DemoMetricsSeeder::class);
    }
}
