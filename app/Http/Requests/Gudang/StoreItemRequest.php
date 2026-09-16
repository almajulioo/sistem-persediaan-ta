<?php

namespace App\Http\Requests\Gudang;

use App\Enums\SatuanBarang;
use App\Models\ItemProposal;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mendaftarkan barang baru ke master barang.
 *
 * Boleh membawa `item_proposal_id`: mendaftarkan barangnya sekaligus menyetujui
 * usulan barang baru itu, karena persetujuan tanpa baris `items` tidak berarti
 * apa pun (ADR 0032).
 */
class StoreItemRequest extends FormRequest
{
    private ?ItemProposal $usulanBarang = null;

    public function authorize(): bool
    {
        return $this->user()?->can('kelola-gudang') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'item_code' => ['required', 'string', 'max:50', 'regex:'.ItemFormRules::ITEM_CODE_REGEX, Rule::unique('items', 'item_code')],
            'item_name' => ['required', 'string', 'max:255'],
            'unit' => ['required', Rule::enum(SatuanBarang::class)],
            'stock_min' => ['required', 'integer', 'min:0', 'max:999999'],
            'stock_max' => ['required', 'integer', 'min:0', 'max:999999', 'gte:stock_min'],
            // Hanya ada saat menambah; tercatat sebagai mutasi "Saldo Awal".
            'stock_awal' => ['required', 'integer', 'min:0', 'max:999999'],
            'is_active' => ['required', 'boolean'],

            // Opsional: hanya terisi bila barangnya lahir dari usulan barang
            // baru (ADR 0032).
            'item_proposal_id' => [
                'nullable',
                Rule::exists('item_proposals', 'item_proposal_id'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $usulan = $this->usulanBarang();

            // Usulan yang sudah diputuskan tidak boleh dipakai dua kali —
            // penjaganya di sini, bukan cuma di create(), karena formulirnya
            // bisa dikirim lagi dari tab yang sudah lama terbuka.
            if ($usulan !== null && $usulan->sudahDiputuskan()) {
                $validator->errors()->add(
                    'item_proposal_id',
                    'Usulan barang ini sudah diputuskan sebelumnya.',
                );
            }
        });
    }

    /**
     * Usulan barang yang sedang ditindaklanjuti, bila ada.
     */
    public function usulanBarang(): ?ItemProposal
    {
        $id = trim((string) $this->input('item_proposal_id', ''));

        if ($id === '') {
            return null;
        }

        return $this->usulanBarang ??= ItemProposal::find($id);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'item_code' => mb_strtoupper(trim((string) $this->input('item_code'))),
            'item_name' => trim((string) $this->input('item_name')),
            'is_active' => $this->boolean('is_active'),
        ]);

        if (trim((string) $this->input('item_proposal_id', '')) === '') {
            $this->merge(['item_proposal_id' => null]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ItemFormRules::attributes();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ItemFormRules::messages();
    }
}
