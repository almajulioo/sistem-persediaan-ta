<?php

namespace App\Http\Requests\Request;

use App\Enums\UserRole;
use App\Models\Request as ModelsRequest;
use App\Models\RequestDetail;
use Illuminate\Validation\Validator;

/**
 * Ubah kuantitas oleh Pengelola Gudang. Aturannya sama dengan saat
 * mengajukan, termasuk nomor urut Nota Dinas: kolomnya sudah terisi nomor
 * dari pemohon dan hanya diubah bila keliru.
 *
 * Bedanya, jumlah boleh 0 (barang itu tidak diberikan), dan setiap jumlah
 * yang tidak sama dengan permintaan wajib disertai alasan untuk pemohon.
 */
class UpdatePermintaanBarangRequest extends StorePermintaanBarangRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $request = $this->route('request');

        return $user !== null
            && $request instanceof ModelsRequest
            && $request->status === 'approved_by_head'
            && $user->hasRole(UserRole::PengelolaGudang, UserRole::Admin, UserRole::KasubbagUmumTi);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'items.*.qty' => ['required', 'integer', 'min:0', 'max:999999'],
            'adjustment_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Jumlah yang ditahan permintaan ini sendiri tidak ikut membatasi.
     */
    protected function excludedRequestId(): ?string
    {
        return $this->route('request')?->request_id;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $note = trim((string) $this->input('adjustment_note'));
        $this->merge(['adjustment_note' => $note === '' ? null : $note]);
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator) {
            $submitted = collect($this->input('items', []))->keyBy('item_id');
            $details = $this->route('request')->item()->get();
            $approvedFor = fn (RequestDetail $detail): int => (int) ($submitted->get($detail->item_id)['qty'] ?? 0);

            if ($details->every(fn (RequestDetail $detail) => $approvedFor($detail) === 0)) {
                $validator->errors()->add('items', 'Bila tidak ada barang yang dapat diberikan, tolak permintaan dengan alasan.');
            }

            if ($this->input('adjustment_note') === null
                && $details->contains(fn (RequestDetail $detail) => $approvedFor($detail) !== $detail->quantity_requested)) {
                $validator->errors()->add('adjustment_note', 'Tulis alasan perubahan jumlah supaya pemohon tahu mengapa jumlahnya berubah.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'adjustment_note' => 'alasan perubahan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'items.*.qty.min' => 'Jumlah disetujui tidak boleh negatif.',
        ];
    }
}
