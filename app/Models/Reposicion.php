<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reposicion extends Model
{
    use HasFactory;

    protected $table = 'reposiciones';

    protected $fillable = [
        'sancion_id',
        'equipo_id',
        'solicitante_id',
        'codigo_equipo_nuevo',
        'observaciones',
        'costo_estimado',
        'estado',
        'fecha_reposicion',
    ];

    protected function casts(): array
    {
        return [
            'solicitante_id' => 'integer',
            'sancion_id' => 'integer',
            'equipo_id' => 'integer',
            'costo_estimado' => 'decimal:2',
            'fecha_reposicion' => 'datetime',
        ];
    }

    public function sancion(): BelongsTo
    {
        return $this->belongsTo(Sancion::class, 'sancion_id');
    }
}
