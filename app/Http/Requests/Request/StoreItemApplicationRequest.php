<?php

namespace App\Http\Requests\Request;

use App\Enums\ItemApplicationType;
use App\Enums\SatuanBarang;
use App\Models\Request as ModelsRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemApplicationRequest extends FormRequest
{
    /**
     * Siapa pun yang login boleh mengajukan permohonan, sama seperti permintaan.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nd_sequence' => ['required', 'regex:'.ModelsRequest::ND_SEQUENCE_PATTERN],
            'purpose' => ['required', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', Rule::enum(ItemApplicationType::class)],
            'items.*.item_id' => [
                'nullable',
                'string',
                'distinct',
                'required_if:items.*.type,'.ItemApplicationType::TambahStok->value,
                Rule::exists('items', 'item_id')->where('is_active', true),
            ],
            'items.*.item_name' => ['nullable', 'string', 'max:255', 'required_if:items.*.type,'.ItemApplicationType::BarangBaru->value],
            'items.*.unit' => ['nullable', Rule::enum(SatuanBarang::class), 'required_if:items.*.type,'.ItemApplicationType::BarangBaru->value],
            'items.*.note' => ['nullable', 'string', 'max:500', 'required_if:items.*.type,'.ItemApplicationType::BarangBaru->value],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999999'],
        ];
    }

    /**
     * Kosongkan kolom milik jenis lain supaya satu baris tidak sekaligus
     * menunjuk barang terdaftar dan membawa nama barang baru.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'nd_sequence' => trim((string) $this->input('nd_sequence')),
            'purpose' => trim((string) $this->input('purpose')),
        ]);

        if (! is_array($this->input('items'))) {
            return;
        }

        $this->merge([
            'items' => array_values(array_map(function ($item): array {
                $item = is_array($item) ? $item : [];
                $isNew = ($item['type'] ?? null) === ItemApplicationType::BarangBaru->value;
                $note = trim((string) ($item['note'] ?? ''));

                return [
                    'type' => $item['type'] ?? null,
                    'item_id' => $isNew ? null : trim((string) ($item['item_id'] ?? '')),
                    'item_name' => $isNew ? trim((string) ($item['item_name'] ?? '')) : null,
                    'unit' => $isNew ? ($item['unit'] ?? null) : null,
                    'note' => $note === '' ? null : $note,
                    'quantity' => $item['quantity'] ?? null,
                ];
            }, $this->input('items'))),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nd_sequence' => 'nomor urut Nota Dinas',
            'purpose' => 'keperluan',
            'items' => 'daftar barang',
            'items.*.type' => 'jenis permohonan',
            'items.*.item_id' => 'barang',
            'items.*.item_name' => 'nama barang baru',
            'items.*.unit' => 'satuan',
            'items.*.note' => 'spesifikasi dan kegunaan',
            'items.*.quantity' => 'jumlah',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nd_sequence.required' => 'Nomor urut Nota Dinas wajib diisi.',
            'nd_sequence.regex' => 'Nomor urut Nota Dinas hanya berupa angka, paling banyak 6 digit.',
            'purpose.required' => 'Jelaskan keperluan permohonan ini supaya Pengelola Gudang dapat menilainya.',
            'items.required' => 'Tambahkan minimal satu barang ke daftar permohonan.',
            'items.min' => 'Tambahkan minimal satu barang ke daftar permohonan.',
            'items.*.item_id.required_if' => 'Pilih barang yang ingin ditambah stoknya.',
            'items.*.item_id.exists' => 'Barang yang dipilih tidak ditemukan atau sudah diarsipkan.',
            'items.*.item_id.distinct' => 'Satu barang tidak boleh muncul dua kali dalam satu permohonan.',
            'items.*.item_name.required_if' => 'Nama barang baru wajib diisi.',
            'items.*.unit.required_if' => 'Satuan barang baru wajib dipilih.',
            'items.*.note.required_if' => 'Jelaskan spesifikasi dan kegunaan barang baru. Tanpa ini Pengelola Gudang tidak punya bahan untuk memutuskan.',
            'items.*.quantity.min' => 'Jumlah minimal 1.',
        ];
    }
}
