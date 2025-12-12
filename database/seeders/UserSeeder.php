<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create test user
        User::firstOrCreate(
            ['email' => 'sahinh013@gmail.com'],
            [
                'name' => 'Test User',
                'password' => Hash::make('Sa@1234567'),
                'role' => 'user',
            ]
        );

        // Create abhi037@gmail.com user as admin
        User::firstOrCreate(
            ['email' => 'abhi037@gmail.com'],
            [
                'name' => 'Abhi Admin',
                'password' => Hash::make('Sa@1234567'),
                'role' => 'admin',
            ]
        );

        $this->command->info('Test user created: sahinh013@gmail.com / Sa@1234567');
        $this->command->info('Admin user created: abhi037@gmail.com / Sa@1234567');
    }
}
