<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sanciones', function (Blueprint $table) {
            $table->id();
            
            // Referencias a los IDs del sistema SOAP (Laragon)
            $table->unsignedBigInteger('prestamo_id');
            $table->unsignedBigInteger('solicitante_id');
            
            // Detalle de la sanción
            $table->enum('tipo', ['Entrega_Tardia', 'Dano', 'Perdida']);
            $table->text('descripcion');
            $table->integer('dias_sancion')->default(0);
            $table->decimal('monto_multa', 10, 2)->default(0.00);
            
            // Estado y vigencia
            $table->enum('estado', ['Activa', 'Cumplida', 'Cancelada'])->default('Activa');
            $table->timestamp('fecha_inicio')->useCurrent();
            $table->timestamp('fecha_fin')->nullable();
            
            $table->timestamps();

            // Índices para mejorar rendimiento en búsquedas
            $table->index('prestamo_id');
            $table->index('solicitante_id');
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sanciones');
    }
};