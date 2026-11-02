<?php

namespace App\Http\Requests\Request;

use App\Enums\UserRole;
use App\Models\Item;
use App\Models\Request as ModelsRequest;
use App\Support\StockReservation;
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
     * Hanya barang yang sudah terdaftar. Barang yang belum terdaftar diajukan
     * lewat menu Permohonan. Nomor urut Nota Dinas diisi pemohon saat
     * mengajukan; nomor lengkapnya disusun RequestWorkflowService.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'emergency' => ['sometimes', 'boolean'],
            'requester_id' => ['nullable', 'uuid', Rule::exists('users', 'user_id')->where('is_active', true)],
            'nd_sequence' => ['required', 'regex:'.ModelsRequest::ND_SEQUENCE_PATTERN],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'string', Rule::exists('items', 'item_id')],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:999999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'emergency' => $this->boolean('emergency'),
            'nd_sequence' => trim((string) $this->input('nd_sequence')),
        ]);

        // Item ID adalah UUID; cukup rapikan whitespace tanpa mengubah casing.
        // Barang yang sama digabung supaya pemeriksaan stok memakai totalnya.
        if ($this->has('items') && is_array($this->items)) {
            $mergedItems = [];
            foreach ($this->items as $item) {
                if (! isset($item['item_id'], $item['qty'])) {
                    continue;
                }

                $itemId = trim((string) $item['item_id']);
                $mergedItems[$itemId] = ($mergedItems[$itemId] ?? 0) + (int) $item['qty'];
            }

            $this->merge([
                'items' => array_map(
                    fn ($itemId, $qty) => ['item_id' => $itemId, 'qty' => $qty],
                    array_keys($mergedItems),
                    array_values($mergedItems),
                ),
            ]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateStock($validator);

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
     * Permintaan tidak boleh melebihi stok tersedia: stok fisik dikurangi
     * yang ditahan permintaan lain yang masih berjalan (StockReservation).
     * Kekurangannya dimohonkan lewat menu Permohonan.
     */
    private function validateStock(Validator $validator): void
    {
        $items = $this->input('items', []);

        if (! is_array($items) || $items === []) {
            return;
        }

        $itemIds = array_column($items, 'item_id');
        $stock = Item::query()
            ->whereIn('item_id', $itemIds)
            ->get(['item_id', 'item_name', 'stock_current'])
            ->keyBy('item_id');
        $reserved = StockReservation::reservedFor($itemIds, $this->excludedRequestId());

        foreach ($items as $index => $item) {
            $found = $stock->get($item['item_id']);

            if ($found === null) {
                continue;
            }

            $held = $reserved->get($found->item_id, 0);
            $available = max(0, (int) $found->stock_current - $held);

            if ((int) $item['qty'] > $available) {
                $validator->errors()->add(
                    "items.{$index}.qty",
                    "Jumlah {$found->item_name} ({$item['qty']}) melebihi stok tersedia ({$available}). "
                        .($held > 0 ? "{$held} ditahan permintaan lain yang masih berjalan. " : '')
                        .'Untuk kekurangannya, ajukan permohonan tambah stok lewat menu Permohonan.',
                );
            }
        }
    }

    /**
     * Permintaan yang tahanannya tidak ikut dihitung. Saat mengajukan belum
     * ada; saat Ubah Kuantitas, permintaan itu sendiri.
     */
    protected function excludedRequestId(): ?string
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'emergency' => 'jenis permintaan',
            'requester_id' => 'peminta',
            'nd_sequence' => 'nomor urut Nota Dinas',
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
            'nd_sequence.required' => 'Nomor urut Nota Dinas wajib diisi.',
            'nd_sequence.regex' => 'Nomor urut Nota Dinas hanya berupa angka, paling banyak 6 digit.',
        ];
    }
}
