<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use App\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'admin',
                'description' => 'Administrator with full system control'
            ],
            [
                'name' => 'manager',
                'description' => 'Manager overseeing delivery operations'
            ],
            [
                'name' => 'driver',
                'description' => 'Delivery driver executing deliveries'
            ],
            [
                'name' => 'user',
                'description' => 'Regular user / customer'
            ],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role['name']], $role);
        }
    }
}
