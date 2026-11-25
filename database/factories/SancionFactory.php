<?php

namespace Database\Factories;

use App\Models\Sancion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Sancion> */
class SancionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'prestamo_id' => fake()->numberBetween(1, 10000),
            'solicitante_id' => fake()->numberBetween(1, 10000),
            'tipo' => 'Dano',
            'descripcion' => fake()->sentence(),
            'dias_sancion' => 0,
            'monto_multa' => 0,
            'estado' => 'Activa',
            'fecha_inicio' => now(),
            'fecha_fin' => null,
        ];
    }
}
