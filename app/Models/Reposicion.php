<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    public function sancion()
    {
        return $this->belongsTo(Sancion::class, 'sancion_id');
    }
}