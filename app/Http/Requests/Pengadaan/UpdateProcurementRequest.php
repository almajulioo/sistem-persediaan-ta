<?php

namespace App\Http\Requests\Pengadaan;

use App\Enums\AlasanPengadaan;
use App\Models\Item;
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
 * Baris yang berasal dari usulan (item_id-nya ada pada rincian yang
 * disetujui) **tidak dapat dihapus** — kunci baris di sini
 * `procurement_detail_id`, baris yang sudah ada, bukan
 * `procurement_request_detail_id` seperti pada pembuatan. Tapi Pejabat
 * Pengadaan boleh menambah barang di luar usulan (kirim `item_id` tanpa
 * `procurement_detail_id`) maupun menghapusnya kembali, dan jumlah pesan pada
 * baris mana pun boleh melebihi jumlah yang disetujui (ADR 0029).
 *
 * `procurement_request_id` sengaja tidak ada di sini: memindahkan realisasi ke
 * usulan lain akan menabrak indeks unik dan menghapus jejak asal-usulnya.
 * Lihat ADR 0025. Karena itu pula keadaan "tanpa usulan" dibaca dari
 * realisasinya sendiri, bukan dari kiriman — realisasi langsung selamanya
 * langsung, dan tetap wajib membawa dasarnya (ADR 0031).
 *
 * Pada realisasi langsung, `rincianDisetujui()` kosong sehingga seluruh baris
 * tergolong baris tambahan: semuanya boleh dihapus, tidak ada yang wajib
 * bertahan.
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
        // Wajib bila realisasi yang sedang diubah memang tidak punya usulan.
        // Dibaca dari rutenya, bukan dari kiriman, supaya kewajibannya tidak
        // dapat dihindari dengan tidak mengirim apa pun.
        $wajibDasar = $this->procurement()?->tanpaUsulan() ?? false;

        return [
            'supplier_id' => [
                'required',
                Rule::exists('suppliers', 'supplier_id')->where('is_active', true),
            ],
            'procurement_date' => ['required', 'date', 'before_or_equal:today'],
            'basis_reason' => [
                $wajibDasar ? 'required' : 'nullable',
                Rule::enum(AlasanPengadaan::class),
            ],
            'basis_note' => [
                $wajibDasar ? 'required' : 'nullable',
                'string',
                'max:500',
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.procurement_detail_id' => ['nullable', 'distinct'],
            'items.*.item_id' => ['nullable', 'distinct', Rule::exists('items', 'item_id')],
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

            $rincian = $this->rincianDisetujui();
            $dikirim = [];
            $itemTerpakai = [];

            foreach ((array) $this->input('items', []) as $index => $baris) {
                $detailId = trim((string) ($baris['procurement_detail_id'] ?? ''));

                if ($detailId !== '') {
                    $detail = $procurement->details->firstWhere('procurement_detail_id', $detailId);

                    if ($detail === null) {
                        $validator->errors()->add(
                            "items.{$index}.procurement_detail_id",
                            'Barang tidak ditemukan pada realisasi ini.',
                        );

                        continue;
                    }

                    $dikirim[] = $detailId;
                    $itemId = $detail->item_id;
                } else {
                    $itemId = trim((string) ($baris['item_id'] ?? ''));
                    $item = $itemId !== '' ? Item::find($itemId) : null;

                    if ($item === null) {
                        $validator->errors()->add(
                            "items.{$index}.item_id",
                            'Pilih barang yang valid untuk ditambahkan.',
                        );

                        continue;
                    }
                }

                if (in_array($itemId, $itemTerpakai, true)) {
                    $validator->errors()->add(
                        "items.{$index}.item_id",
                        'Barang ini sudah ada pada daftar realisasi.',
                    );

                    continue;
                }

                $itemTerpakai[] = $itemId;
            }

            // Baris dari usulan wajib tetap ada — hanya baris tambahan (di
            // luar usulan) yang boleh hilang dari kiriman, artinya sengaja
            // dihapus. Lihat ADR 0029.
            $hilang = $procurement->details
                ->filter(fn (ProcurementDetail $detail) => $rincian->has($detail->item_id))
                ->reject(fn (ProcurementDetail $detail) => in_array($detail->procurement_detail_id, $dikirim, true));

            if ($hilang->isNotEmpty()) {
                $validator->errors()->add('items', sprintf(
                    'Barang dari usulan wajib tetap ada pada realisasi. Belum terisi: %s.',
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
     * Rincian usulan yang disetujui, atau koleksi kosong bila realisasinya
     * langsung.
     *
     * Guard-nya eksplisit dan bukan sekadar kehati-hatian: tanpa itu kueri
     * berjalan dengan nilai null, yang oleh Laravel diubah menjadi
     * `where ... is null`. Kebetulan hasilnya kosong karena
     * `procurement_request_details.procurement_request_id` NOT NULL, tetapi
     * bersandar pada kebetulan semacam itu rapuh.
     *
     * @return Collection<string, ProcurementRequestDetail>
     */
    private function rincianDisetujui(): Collection
    {
        $usulanId = $this->procurement()?->procurement_request_id;

        if ($usulanId === null) {
            return $this->rincianDisetujui ??= new Collection;
        }

        return $this->rincianDisetujui ??= ProcurementRequestDetail::query()
            ->where('procurement_request_id', $usulanId)
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
            'basis_reason' => 'dasar pengadaan',
            'basis_note' => 'keterangan dasar pengadaan',
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
            'basis_reason.required' => 'Pilih dasar pengadaan. Realisasi tanpa usulan wajib menyebutkan dasarnya.',
            'basis_note.required' => 'Jelaskan dasar pengadaannya. Keterangan ini yang dinilai Kasubbag Umum dan TI.',
            'items.required' => 'Daftar barang tidak boleh kosong.',
            'items.*.amount.min' => 'Jumlah pesan minimal 1.',
        ];
    }
}
