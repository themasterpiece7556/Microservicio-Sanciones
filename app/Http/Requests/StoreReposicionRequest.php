<?php

namespace App\Http\Requests;

use App\Models\Reposicion;
use App\Models\Sancion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class StoreReposicionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('create', Reposicion::class);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'sancion_id' => ['required', 'integer', 'exists:sanciones,id'],
            'equipo_id' => ['required', 'integer', 'min:1'],
            'solicitante_id' => ['required', 'integer', 'min:1'],
            'costo_estimado' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'observaciones' => ['nullable', 'string'],
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['sancion_id', 'solicitante_id'])) {
                return;
            }

            $sancion = Sancion::find($this->integer('sancion_id'));
            if (! $sancion || $sancion->solicitante_id !== $this->integer('solicitante_id')) {
                $validator->errors()->add('solicitante_id', 'El solicitante no corresponde a la sanción.');
            }
        }];
    }
}
