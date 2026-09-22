<?php

namespace App\Http\Requests\Request;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MergePermintaanUmumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(UserRole::Admin, UserRole::PengelolaGudang) ?? false;
    }

    public function rules(): array
    {
        return [
            'request_ids' => ['required', 'array', 'min:1'],
            'request_ids.*' => [
                'required',
                'uuid',
                Rule::exists('requests', 'request_id')->where(fn ($query) => $query
                    ->where('type', 'umum')
                    ->where('status', 'submitted')),
            ],
        ];
    }
}