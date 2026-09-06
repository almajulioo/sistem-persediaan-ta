<?php

namespace App\Http\Requests\Pengadaan;

use App\Models\ProcurementRequestDetail;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Persetujuan usulan pengadaan, per barang.
 *
 * `granted` sengaja tidak divalidasi `max` lewat aturan statis — batasnya
 * (`amount_request` tiap barang) berbeda per baris, sehingga diperiksa lewat
 * `withValidator()` yang punya akses ke rincian usulan sesungguhnya.
 */
class ApproveProcurementRequestRequest extends FormRequest
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
            'granted' => ['required', 'array', 'min:1'],
            'granted.*' => ['required', 'integer', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('granted') && is_array($this->granted)) {
            $this->merge([
                'granted' => array_map(fn ($jumlah) => (int) $jumlah, $this->granted),
            ]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $procurementRequest = $this->route('procurementRequest');

            // Usulan yang sudah diputuskan ditolak controller dengan pesan yang
            // tepat. Tanpa penjagaan ini, validasi di bawah berjalan lebih dulu
            // dan pengguna bisa menerima pesan yang menyesatkan.
            if (! $procurementRequest->isDiajukan()) {
                return;
            }

            $details = $procurementRequest->details->keyBy('procurement_request_detail_id');

            $adaYangDisetujui = false;

            foreach ((array) $this->input('granted', []) as $detailId => $jumlah) {
                /** @var ProcurementRequestDetail|null $detail */
                $detail = $details->get((string) $detailId);

                if ($detail === null) {
                    $validator->errors()->add('granted', 'Rincian barang tidak ditemukan pada usulan ini.');

                    continue;
                }

                if ((int) $jumlah > $detail->amount_request) {
                    $validator->errors()->add(
                        "granted.{$detailId}",
                        "Jumlah disetujui untuk {$detail->item?->item_name} tidak boleh melebihi jumlah diminta ({$detail->amount_request}).",
                    );
                }

                if ((int) $jumlah > 0) {
                    $adaYangDisetujui = true;
                }
            }

            if (! $adaYangDisetujui) {
                $validator->errors()->add('granted', 'Setujui minimal satu barang dengan jumlah lebih dari 0, atau tolak usulan ini.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'granted.required' => 'Isi keputusan untuk setiap barang pada usulan ini.',
        ];
    }
}
