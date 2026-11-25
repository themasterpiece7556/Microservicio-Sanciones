<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class RoleUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $usuarios = [
            [
                'name' => 'Administrador',
                'email' => 'administrador@prestamos.local',
                'password' => '123456',
                'role' => 'administrador',
            ],
            [
                'name' => 'Encargado de Laboratorio',
                'email' => 'encargado_laboratorio@prestamos.local',
                'password' => '123456',
                'role' => 'encargado_laboratorio',
            ],
            [
                'name' => 'Almacenista',
                'email' => 'almacenista@prestamos.local',
                'password' => '123456',
                'role' => 'almacenista',
            ],
            [
                'name' => 'Solicitante',
                'email' => 'solicitante@prestamos.local',
                'password' => '123456',
                'role' => 'solicitante',
            ],
        ];

        foreach ($usuarios as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                $data
            );
        }
    }
}
