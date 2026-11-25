<?php

namespace App\Http\Requests;

use App\Models\Sancion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreSancionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('create', Sancion::class);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $maxDays = max(0, (int) floor((2147483647 - now()->getTimestamp()) / 86400));

        return [
            'prestamo_id' => ['required', 'integer', 'min:1'],
            'solicitante_id' => ['required', 'integer', 'min:1'],
            'tipo' => ['required', 'in:Entrega_Tardia,Dano,Perdida'],
            'descripcion' => ['required', 'string'],
            'dias_sancion' => ['nullable', 'integer', 'min:0', 'max:'.$maxDays],
            'monto_multa' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ];
    }
}
