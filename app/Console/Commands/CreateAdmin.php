<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * The first administrator on a new installation (README "First administrator").
 * Users are otherwise created by an administrator in Settings › Users, and an
 * account needs a warehouse to open any page, so on an empty system this also
 * creates the first warehouse. The new admin works in every active warehouse.
 */
class CreateAdmin extends Command
{
    protected $signature = 'user:create-admin
        {--email= : Email address to sign in with}
        {--name= : Full name}
        {--warehouse-code= : Code of the first warehouse (only asked when there is none)}
        {--warehouse-name= : Name of the first warehouse}
        {--warehouse-city= : City of the first warehouse}';

    protected $description = 'Create an administrator account (and the first warehouse on an empty system).';

    public function handle(): int
    {
        $email = (string) ($this->option('email') ?: $this->ask('Email address'));
        $name = (string) ($this->option('name') ?: $this->ask('Full name'));
        $password = (string) $this->secret('Password (at least 12 characters)');

        $validator = Validator::make(compact('email', 'name', 'password'), [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (User::whereRaw('lower(email) = lower(?)', [$email])->exists()) {
            $this->error("A user with the email $email already exists.");

            return self::FAILURE;
        }

        DB::transaction(function () use ($email, $name, $password) {
            if (! Warehouse::where('active', true)->exists()) {
                $this->info('There is no warehouse yet: an account needs one to open any page.');
                Warehouse::create([
                    'code' => mb_strtoupper((string) ($this->option('warehouse-code') ?: $this->ask('First warehouse code (e.g. BEY)'))),
                    'name' => (string) ($this->option('warehouse-name') ?: $this->ask('First warehouse name')),
                    'city' => (string) ($this->option('warehouse-city') ?: $this->ask('City')),
                ]);
            }

            $user = User::create([
                'full_name' => $name,
                'email' => $email,
                'password_hash' => Hash::make($password),
                'preferred_lang' => config('app.locale'),
                'active' => true,
            ]);
            $user->roles()->sync(Role::where('code', 'ADMIN')->firstOrFail());
            $user->warehouses()->sync(Warehouse::where('active', true)->pluck('id'));

            Audit::record('app_user', $user->id, 'CREATE', null, ['email' => $email, 'full_name' => $name, 'roles' => ['ADMIN'], 'by' => 'user:create-admin']);
        });

        $this->info("Administrator $email created. Sign in and add warehouses, customers and users in Settings.");

        return self::SUCCESS;
    }
}
