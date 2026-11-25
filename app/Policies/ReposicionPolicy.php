<?php

namespace App\Policies;

use App\Models\Reposicion;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ReposicionPolicy
{
    public function create(User $user): bool
    {
        return in_array($user->role, ['administrador', 'almacenista'], true);
    }

    public function view(User $user, Reposicion $reposicion): Response
    {
        if (in_array($user->role, ['administrador', 'almacenista'], true) || ($user->role === 'solicitante' && $user->solicitante_id === $reposicion->solicitante_id)) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }
}
