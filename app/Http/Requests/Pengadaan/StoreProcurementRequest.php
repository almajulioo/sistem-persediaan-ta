<?php

namespace App\Http\Requests\Pengadaan;

use App\Models\ProcurementRequest;
use App\Models\ProcurementRequestDetail;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Realisasi pengadaan dari satu usulan yang sudah disetujui.
 *
 * `item_id` sengaja tidak diminta dari klien — diturunkan server-side dari
 * `procurement_request_detail_id` lewat `rincianDisetujui()`, pola yang sama
 * dengan `ApproveProcurementRequestRequest`, supaya barang pada realisasi
 * tidak mungkin menyimpang dari rincian usulan yang sesungguhnya disetujui.
 */
class StoreProcurementRequest extends FormRequest
{
    /**
     * Rincian usulan yang disetujui (amount_granted > 0), dimuat sekali.
     *
     * @var Collection<string, ProcurementRequestDetail>|null
     */
    private ?Collection $rincianDisetujui = null;

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
            'procurement_request_id' => [
                'required',
                Rule::exists('procurement_requests', 'procurement_request_id'),
            ],
            'supplier_id' => [
                'required',
                Rule::exists('suppliers', 'supplier_id')->where('is_active', true),
            ],
            'procurement_date' => ['required', 'date', 'before_or_equal:today'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.procurement_request_detail_id' => ['required', 'distinct'],
            'items.*.amount' => ['required', 'integer', 'min:1', 'max:999999'],
            'items.*.unit_price' => ['required', 'integer', 'min:0', 'max:999999999999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $procurementRequest = $this->usulan();

            if ($procurementRequest === null) {
                return;
            }

            if ($procurementRequest->isDisetujuiSelesai()) {
                $validator->errors()->add('procurement_request_id', 'Usulan ini sudah memiliki realisasi.');

                return;
            }

            if (! $procurementRequest->isDisetujuiMenungguRealisasi()) {
                $validator->errors()->add('procurement_request_id', 'Realisasi hanya dapat dibuat dari usulan yang sudah disetujui.');

                return;
            }

            foreach ((array) $this->input('items', []) as $index => $baris) {
                $detailId = (string) ($baris['procurement_request_detail_id'] ?? '');
                $rincian = $this->rincianDisetujui()->get($detailId);

                if ($rincian === null) {
                    $validator->errors()->add(
                        "items.{$index}.procurement_request_detail_id",
                        'Barang tidak ditemukan pada rincian usulan yang disetujui.',
                    );

                    continue;
                }

                if ((int) ($baris['amount'] ?? 0) > $rincian->amount_granted) {
                    $validator->errors()->add(
                        "items.{$index}.amount",
                        "Jumlah pesan untuk {$rincian->item?->item_name} tidak boleh melebihi jumlah disetujui ({$rincian->amount_granted}).",
                    );
                }
            }
        });
    }

    private function usulan(): ?ProcurementRequest
    {
        $id = (string) $this->input('procurement_request_id', '');

        if ($id === '') {
            return null;
        }

        return ProcurementRequest::find($id);
    }

    /**
     * @return Collection<string, ProcurementRequestDetail>
     */
    public function rincianDisetujui(): Collection
    {
        return $this->rincianDisetujui ??= ProcurementRequestDetail::query()
            ->with('item')
            ->where('procurement_request_id', (string) $this->input('procurement_request_id'))
            ->where('amount_granted', '>', 0)
            ->get()
            ->keyBy('procurement_request_detail_id');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'supplier_id' => 'penyedia',
            'procurement_date' => 'tanggal realisasi',
            'items' => 'daftar barang',
            'items.*.amount' => 'jumlah pesan',
            'items.*.unit_price' => 'harga satuan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'procurement_request_id.required' => 'Usulan pengadaan tidak ditemukan.',
            'items.required' => 'Tambahkan minimal satu barang ke realisasi.',
            'items.min' => 'Tambahkan minimal satu barang ke realisasi.',
            'items.*.amount.min' => 'Jumlah pesan minimal 1.',
        ];
    }
}
