<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Local development only: an administrator "admin" with password "password".
     * Production installs create their first account in the setup wizard.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        User::query()->firstOrCreate(['username' => 'admin'], [
            'name' => 'Local Admin',
            'password' => 'password',
            'is_admin' => true,
        ]);
    }
}
