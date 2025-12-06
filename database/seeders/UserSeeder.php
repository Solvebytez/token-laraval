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

        $this->command->info('Test user created: sahinh013@gmail.com / Sa@1234567');
    }
}
