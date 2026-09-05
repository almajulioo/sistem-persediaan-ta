<?php

namespace App\Http\Requests\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'string', Rule::exists('items', 'item_id')],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:999999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Memastikan item_id terformat uppercase & kuantitas berupa integer
        if ($this->has('items') && is_array($this->items)) {
            $formattedItems = array_map(function ($item) {
                if (isset($item['item_id'])) {
                    $item['item_id'] = mb_strtoupper(trim((string) $item['item_id']));
                }
                if (isset($item['qty'])) {
                    $item['qty'] = (int) $item['qty'];
                }
                return $item;
            }, $this->items);

            $this->merge([
                'items' => $formattedItems,
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
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
        ];
    }
}