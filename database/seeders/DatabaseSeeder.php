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
        $this->call(MasterDataSeeder::class);

        if (app()->environment('production')) {
            $this->command?->warn('Development and QA bootstrap data was not seeded in production.');
            //crear usuario de administrador
            \App\Models\User::create([
                'name' => 'Administrador',
                'email' => 'admin@aaud.gob.pa',
                'password' => bcrypt('admin123'),
                'role' => 'admin',
            ]);

            return;
        }

        $this->call(DevelopmentBootstrapSeeder::class);
    }
}
