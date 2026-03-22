<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DefaultUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'dccoc12@gmail.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('!Asdasd123'),
            ]
        );
    }
}
