<?php

namespace App\Http\Requests\Pengadaan;

use App\Models\Procurement;
use App\Models\ProcurementDetail;
use App\Models\ProcurementRequestDetail;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Koreksi realisasi pengadaan yang belum dikonfirmasi diterima.
 *
 * Berbeda dari `StoreProcurementRequest`, barisnya **tidak dapat ditambah
 * maupun dihapus**: susunan barang ditetapkan usulan yang disetujui, dan satu
 * usulan hanya menghasilkan satu realisasi selamanya (ADR 0016). Karena itu
 * kunci baris di sini `procurement_detail_id` — baris yang sudah ada — bukan
 * `procurement_request_detail_id` seperti pada pembuatan.
 *
 * Aturan ADR 0021 "wajib mencakup seluruh rincian yang disetujui" terpenuhi
 * dengan sendirinya, jadi yang perlu dijaga tinggal kelengkapan kirimannya.
 *
 * `procurement_request_id` sengaja tidak ada di sini: memindahkan realisasi ke
 * usulan lain akan menabrak indeks unik dan menghapus jejak asal-usulnya.
 * Lihat ADR 0025.
 */
class UpdateProcurementRequest extends FormRequest
{
    /**
     * Rincian usulan yang disetujui, dikunci `item_id`, dimuat sekali.
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
            'supplier_id' => [
                'required',
                Rule::exists('suppliers', 'supplier_id')->where('is_active', true),
            ],
            'procurement_date' => ['required', 'date', 'before_or_equal:today'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.procurement_detail_id' => ['required', 'distinct'],
            'items.*.amount' => ['required', 'integer', 'min:1', 'max:999999'],
            'items.*.unit_price' => ['required', 'integer', 'min:0', 'max:999999999999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $procurement = $this->procurement();

            if ($procurement === null) {
                return;
            }

            $dikirim = [];

            foreach ((array) $this->input('items', []) as $index => $baris) {
                $detailId = (string) ($baris['procurement_detail_id'] ?? '');
                $detail = $procurement->details->firstWhere('procurement_detail_id', $detailId);

                if ($detail === null) {
                    $validator->errors()->add(
                        "items.{$index}.procurement_detail_id",
                        'Barang tidak ditemukan pada realisasi ini.',
                    );

                    continue;
                }

                $dikirim[] = $detailId;

                // Batas atas tetap jumlah yang disetujui usulan, sama seperti
                // saat realisasi dibuat — mengubah tidak boleh menjadi celah
                // untuk memesan melebihi persetujuan.
                $granted = $this->rincianDisetujui()->get($detail->item_id)?->amount_granted;

                if ($granted !== null && (int) ($baris['amount'] ?? 0) > $granted) {
                    $validator->errors()->add(
                        "items.{$index}.amount",
                        "Jumlah pesan untuk {$detail->item?->item_name} tidak boleh melebihi jumlah disetujui ({$granted}).",
                    );
                }
            }

            // Baris yang hilang dari kiriman akan tertinggal memakai nilai
            // lamanya tanpa disadari siapa pun, jadi kelengkapannya dituntut
            // di muka — alasan yang sama dengan konfirmasi penerimaan.
            $hilang = $procurement->details
                ->reject(fn (ProcurementDetail $detail) => in_array($detail->procurement_detail_id, $dikirim, true));

            if ($hilang->isNotEmpty()) {
                $validator->errors()->add('items', sprintf(
                    'Seluruh barang pada realisasi wajib dikirim. Belum terisi: %s.',
                    $hilang->map(fn (ProcurementDetail $detail) => $detail->item?->item_name ?? 'barang tidak dikenal')
                        ->implode(', '),
                ));
            }
        });
    }

    private function procurement(): ?Procurement
    {
        $procurement = $this->route('procurement');

        return $procurement instanceof Procurement
            ? $procurement->loadMissing('details.item')
            : null;
    }

    /**
     * @return Collection<string, ProcurementRequestDetail>
     */
    private function rincianDisetujui(): Collection
    {
        return $this->rincianDisetujui ??= ProcurementRequestDetail::query()
            ->where('procurement_request_id', $this->procurement()?->procurement_request_id)
            ->where('amount_granted', '>', 0)
            ->get()
            // Dikunci item_id, bukan primary key: `procurement_details` tidak
            // menyimpan tautan balik ke baris usulannya. Aman karena
            // StoreProcurementRequestRequest menjaga item_id tetap `distinct`,
            // sehingga satu barang muncul paling banyak sekali per usulan.
            ->keyBy('item_id');
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
            'items.required' => 'Daftar barang tidak boleh kosong.',
            'items.*.amount.min' => 'Jumlah pesan minimal 1.',
        ];
    }
}
