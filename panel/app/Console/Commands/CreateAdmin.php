<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateAdmin extends Command
{
    protected $signature = 'whp:admin {email} {--name=Administrator} {--password=} {--locale=en}';

    protected $description = 'Create an admin account, or reset the password of an existing one';

    public function handle(): int
    {
        $password = $this->option('password') ?: Str::password(20);
        $user = User::updateOrCreate(['email' => $this->argument('email')], [
            'name' => $this->option('name'),
            'password' => $password,
            'role' => User::ROLE_ADMIN,
            'locale' => $this->option('locale'),
            'is_active' => true,
        ]);

        $this->info("Admin {$user->email} is ready.");
        if (! $this->option('password')) {
            $this->line("Password: {$password}");
        }
        $this->line('Two-factor authentication is set up at the first login.');

        return self::SUCCESS;
    }
}
