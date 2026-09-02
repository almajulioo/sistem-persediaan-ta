<?php

namespace App\Http\Requests\Gudang;

use App\Enums\MutationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMutationRequest extends FormRequest
{
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
            'item_id' => [
                'required',
                'uuid',
                Rule::exists('items', 'item_id')->where('is_active', true),
            ],
            'mutation_type' => ['required', Rule::enum(MutationType::class)],
            'amount' => ['required', 'integer', 'min:1', 'max:999999'],
            'mutation_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['required', 'string', 'max:500'],
            // Hanya dipakai jenis dua arah seperti Koreksi Pencatatan.
            'arah' => ['nullable', Rule::in(['tambah', 'kurang'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['notes' => trim((string) $this->input('notes'))]);
    }

    /**
     * Formulir manual hanya boleh mencatat jenis mutasi manual. Jenis yang
     * berasal dari dokumen (Masuk, Keluar, Saldo Awal, Penyesuaian) ditolak
     * supaya tidak ada mutasi yang seolah-olah lahir dari pengadaan,
     * permintaan, atau stock opname.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->mutationType();

            if ($type !== null && ! $type->isManual()) {
                $validator->errors()->add(
                    'mutation_type',
                    'Jenis mutasi ini hanya dapat dibuat oleh sistem dari dokumen sumbernya, bukan dicatat manual.',
                );
            }

            if ($type?->isBidirectional() && blank($this->input('arah'))) {
                $validator->errors()->add('arah', 'Arah koreksi wajib dipilih.');
            }
        });
    }

    public function mutationType(): ?MutationType
    {
        return MutationType::tryFrom((string) $this->input('mutation_type'));
    }

    /**
     * Jumlah bertanda untuk jenis dua arah; untuk jenis berarah tetap tandanya
     * ditentukan StockLedger.
     */
    public function signedAmount(): int
    {
        $amount = (int) $this->input('amount');

        if ($this->mutationType()?->isBidirectional() && $this->input('arah') === 'kurang') {
            return -$amount;
        }

        return $amount;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'item_id' => 'barang',
            'mutation_type' => 'jenis mutasi',
            'amount' => 'jumlah',
            'mutation_date' => 'tanggal mutasi',
            'notes' => 'keterangan',
            'arah' => 'arah koreksi',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'item_id.exists' => 'Barang tidak ditemukan atau sudah diarsipkan.',
            'mutation_type.enum' => 'Jenis mutasi yang dipilih tidak dikenali.',
            'amount.min' => 'Jumlah minimal :min.',
            'mutation_date.before_or_equal' => 'Tanggal mutasi tidak boleh melebihi hari ini.',
            'notes.max' => 'Keterangan maksimal :max karakter.',
        ];
    }
}
