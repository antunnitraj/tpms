<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Usernames;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdminCommand extends Command
{
    protected $signature = 'app:create-admin {--username=} {--name=} {--email=} {--password=}';

    protected $description = 'Create an administrator account (the browser setup wizard does the same on first run)';

    public function handle(): int
    {
        $interactive = $this->input->isInteractive();
        $name = $this->option('name') ?? ($interactive ? $this->ask('Name') : $this->option('username'));
        $suggested = Usernames::suggest($name);
        $username = $this->option('username') ?? ($interactive ? $this->ask('Username', $suggested) : $suggested);

        $data = [
            'username' => $username,
            'name' => $name ?: $username,
            'email' => $this->option('email') ?? ($interactive ? $this->ask('Email (optional)') : null),
            'password' => $this->option('password') ?? $this->secret('Password'),
        ];

        $validator = Validator::make($data, [
            'username' => ['required', 'alpha_dash', 'max:50', 'unique:users,username'],
            'name' => ['required', 'max:255'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::query()->create([
            'username' => $data['username'],
            'name' => $data['name'],
            'email' => $data['email'] ?: null,
            'password' => Hash::make($data['password']),
            'is_admin' => true,
        ]);

        $this->info("Administrator {$data['username']} created.");

        return self::SUCCESS;
    }
}
