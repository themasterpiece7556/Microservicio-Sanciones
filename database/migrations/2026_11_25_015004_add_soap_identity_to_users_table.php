<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('soap_username')->nullable()->unique();
            $table->unsignedBigInteger('solicitante_id')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['soap_username']);
            $table->dropIndex(['solicitante_id']);
            $table->dropColumn(['soap_username', 'solicitante_id']);
        });
    }
};
