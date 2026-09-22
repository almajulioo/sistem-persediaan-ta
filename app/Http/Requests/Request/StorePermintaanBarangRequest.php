<?php

namespace App\Http\Requests\Request;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePermintaanBarangRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'emergency' => ['sometimes', 'boolean'],
            'requester_id' => ['nullable', 'uuid', Rule::exists('users', 'user_id')->where('is_active', true)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'string', Rule::exists('items', 'item_id')],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:999999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'emergency' => $this->boolean('emergency'),
        ]);

        // Item ID adalah UUID; cukup rapikan whitespace tanpa mengubah casing.
        if ($this->has('items') && is_array($this->items)) {
            $mergedItems = [];
            foreach ($this->items as $item) {
                if (isset($item['item_id'])) {
                    $item['item_id'] = trim((string) $item['item_id']);
                }
                if (isset($item['qty'])) {
                    $item['qty'] = (int) $item['qty'];
                }
                if (isset($item['item_id'], $item['qty'])) {
                    $mergedItems[$item['item_id']] = ($mergedItems[$item['item_id']] ?? 0) + $item['qty'];
                }
            }

            $formattedItems = array_map(
                fn ($itemId, $qty) => ['item_id' => $itemId, 'qty' => $qty],
                array_keys($mergedItems),
                array_values($mergedItems),
            );

            $this->merge([
                'items' => $formattedItems,
            ]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->boolean('emergency')) {
                return;
            }

            $user = $this->user();
            if (! $user?->hasRole(UserRole::Admin, UserRole::KasubbagUmumTi, UserRole::PengelolaGudang)) {
                $validator->errors()->add('emergency', 'Hanya Admin atau Pengelola Gudang yang dapat membuat permintaan darurat.');
            }

            if (blank($this->input('requester_id'))) {
                $validator->errors()->add('requester_id', 'Peminta wajib dipilih untuk permintaan darurat.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'emergency' => 'jenis permintaan',
            'requester_id' => 'peminta',
            'items' => 'Daftar Barang',
            'items.*.item_id' => 'Kode Barang',
            'items.*.qty' => 'Jumlah Barang',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Minimal tambahkan 1 barang ke dalam daftar permintaan.',
            'items.array' => 'Format data barang tidak valid.',
            'items.min' => 'Minimal tambahkan 1 barang ke dalam daftar permintaan.',
            'items.*.item_id.required' => 'Barang harus dipilih.',
            'items.*.item_id.exists' => 'Barang yang dipilih tidak ditemukan di database.',
            'items.*.qty.required' => 'Jumlah barang wajib diisi.',
            'items.*.qty.integer' => 'Jumlah barang harus berupa angka.',
            'items.*.qty.min' => 'Jumlah barang minimal 1.',
            'requester_id.exists' => 'Peminta yang dipilih tidak ditemukan atau sudah nonaktif.',
            'requester_id.uuid' => 'Format peminta tidak valid.',
        ];
    }
}