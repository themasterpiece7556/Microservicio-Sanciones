<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reposiciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sancion_id')->constrained('sanciones')->onDelete('cascade');
            $table->unsignedBigInteger('equipo_id');
            $table->unsignedBigInteger('solicitante_id');
            $table->string('codigo_equipo_nuevo')->nullable();
            $table->text('observaciones')->nullable();
            $table->decimal('costo_estimado', 10, 2)->default(0.00);
            $table->enum('estado', ['Pendiente', 'Completada', 'Rechazada'])->default('Pendiente');
            $table->timestamp('fecha_reposicion')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reposiciones');
    }
};