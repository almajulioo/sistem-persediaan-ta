<?php

namespace App\Http\Requests\Gudang;

use App\Enums\SatuanBarang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mengusulkan barang baru untuk didaftarkan ke master barang (ADR 0032).
 *
 * Berada di folder Gudang bersama modulnya — subjeknya master barang, dan
 * pemutusnya Pengelola Gudang — walaupun yang berwenang mengajukan justru
 * pemegang `kelola-pengadaan`. Pemisahan itu memang intinya.
 *
 * Sengaja tidak memeriksa kemiripan nama dengan barang yang sudah ada:
 * pemeriksaan duplikat adalah pekerjaan Pengelola Gudang yang mengenal
 * penamaan bakunya, bukan pekerjaan validator yang hanya bisa mencocokkan
 * huruf. "Spidol warna emas" dan "Spidol Permanen Emas" tidak akan pernah
 * tertangkap aturan `unique`.
 */
class StoreItemProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('kelola-pengadaan') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'item_name' => ['required', 'string', 'max:255'],
            'unit' => ['required', Rule::enum(SatuanBarang::class)],
            'purpose_note' => ['required', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['item_name' => trim((string) $this->input('item_name'))]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'item_name' => 'nama barang',
            'unit' => 'satuan',
            'purpose_note' => 'kegunaan barang',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'purpose_note.required' => 'Jelaskan kegunaan barangnya. Tanpa ini Pengelola Gudang tidak punya bahan untuk memutuskan.',
            'unit.enum' => 'Pilih satuan yang tersedia.',
        ];
    }
}
