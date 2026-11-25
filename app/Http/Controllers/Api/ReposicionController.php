<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReposicionRequest;
use App\Models\Reposicion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReposicionController extends Controller
{
    public function store(StoreReposicionRequest $request): JsonResponse
    {
        $reposicion = Reposicion::create([...$request->validated(), 'estado' => 'Pendiente']);

        return response()->json(['exito' => true, 'datos' => $reposicion], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $recordId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($recordId === false, 404);

        $query = Reposicion::query();
        if ($request->user()->role === 'solicitante') {
            $query->where('solicitante_id', $request->user()->solicitante_id)
                ->whereHas('sancion', fn (Builder $sancion): Builder => $sancion->where('solicitante_id', $request->user()->solicitante_id));
        }
        $reposicion = $query->with('sancion')->findOrFail($recordId);
        Gate::forUser($request->user())->authorize('view', $reposicion);

        return response()->json(['exito' => true, 'datos' => $reposicion]);
    }
}
