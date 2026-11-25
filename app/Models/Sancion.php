<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sancion extends Model
{
    use HasFactory;

    protected $table = 'sanciones';

    protected $fillable = [
        'prestamo_id',
        'solicitante_id',
        'tipo',
        'descripcion',
        'dias_sancion',
        'monto_multa',
        'estado',
        'fecha_inicio',
        'fecha_fin',
    ];

    protected function casts(): array
    {
        return [
            'solicitante_id' => 'integer',
            'prestamo_id' => 'integer',
            'dias_sancion' => 'integer',
            'monto_multa' => 'decimal:2',
            'fecha_inicio' => 'datetime',
            'fecha_fin' => 'datetime',
        ];
    }

    public function reposiciones(): HasMany
    {
        return $this->hasMany(Reposicion::class, 'sancion_id');
    }
}
