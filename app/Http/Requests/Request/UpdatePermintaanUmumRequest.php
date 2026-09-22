<?php

namespace App\Http\Requests\Request;

use App\Enums\UserRole;
use App\Models\Request as ModelsRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePermintaanUmumRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $request = $this->route('request');

        return $user !== null
            && $request instanceof ModelsRequest
            && $request->type === 'umum'
            && $request->status === 'submitted'
            && $user->hasRole(UserRole::Admin, UserRole::PengelolaGudang);
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'uuid', Rule::exists('items', 'item_id')->where('is_active', true)],
            'items.*.qty' => ['required', 'integer', 'min:0', 'max:999999'],
        ];
    }
}