<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSancionRequest;
use App\Models\Sancion;
use App\Services\SoapLoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class SancionController extends Controller
{
    public function store(StoreSancionRequest $request, SoapLoanService $soap): JsonResponse
    {
        Gate::forUser($request->user())->authorize('consultar-prestamos', 'registrarSancion');

        try {
            $prestamos = $soap->prestamos();
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'No se pudo consultar el servicio SOAP.'], 502);
        }

        $data = $request->validated();
        if (! collect($prestamos)->contains(fn (array $prestamo): bool => (int) $prestamo['id'] === (int) $data['prestamo_id'] && (int) $prestamo['solicitante_id'] === (int) $data['solicitante_id'])) {
            throw ValidationException::withMessages(['prestamo_id' => 'El préstamo no corresponde al solicitante.']);
        }

        $dias = $data['dias_sancion'] ?? 0;
        $sancion = Sancion::create([
            ...$data,
            'dias_sancion' => $dias,
            'monto_multa' => $data['monto_multa'] ?? 0,
            'estado' => 'Activa',
            'fecha_inicio' => now(),
            'fecha_fin' => $dias > 0 ? now()->addDays($dias) : null,
        ]);

        return response()->json(['exito' => true, 'datos' => $sancion], 201);
    }

    public function consultarPorSolicitante(Request $request, string $id, SoapLoanService $soap): JsonResponse
    {
        $solicitanteId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($solicitanteId === false, 404);
        $id = $solicitanteId;

        Gate::forUser($request->user())->authorize('viewForApplicant', [Sancion::class, $id]);
        Gate::forUser($request->user())->authorize('consultar-prestamos', 'consultarSancionesSolicitante');

        try {
            $prestamos = array_values(array_filter($soap->prestamos(), fn (array $prestamo): bool => (int) $prestamo['solicitante_id'] === $id));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'No se pudo consultar el servicio SOAP.'], 502);
        }

        return response()->json([
            'exito' => true,
            'datos' => [
                'sanciones' => Sancion::where('solicitante_id', $id)->get(),
                'prestamos_soap' => $prestamos,
            ],
        ]);
    }
}
