<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Demo users, warehouses and customers are for local development only.
        if (app()->environment('local', 'testing')) {
            $this->call(LocalSeeder::class);
        }
    }
}
