<?php

namespace Database\Factories;

use App\Models\Reposicion;
use App\Models\Sancion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Reposicion> */
class ReposicionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sancion_id' => Sancion::factory(),
            'solicitante_id' => fn (array $attributes): int => Sancion::findOrFail($attributes['sancion_id'])->solicitante_id,
            'equipo_id' => fake()->numberBetween(1, 10000),
            'costo_estimado' => 0,
            'estado' => 'Pendiente',
        ];
    }
}
