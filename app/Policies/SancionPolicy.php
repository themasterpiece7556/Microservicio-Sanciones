<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class SancionPolicy
{
    public function create(User $user): bool
    {
        return in_array($user->role, ['administrador', 'encargado_laboratorio'], true);
    }

    public function viewForApplicant(User $user, int $solicitanteId): Response
    {
        if (in_array($user->role, ['administrador', 'encargado_laboratorio'], true) || ($user->role === 'solicitante' && $user->solicitante_id === $solicitanteId)) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }
}
