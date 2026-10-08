<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    public function reposiciones()
    {
        return $this->hasMany(Reposicion::class, 'sancion_id');
    }
}