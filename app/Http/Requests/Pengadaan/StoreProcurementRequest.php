<?php

namespace App\Http\Requests\Pengadaan;

use App\Enums\AlasanPengadaan;
use App\Models\Item;
use App\Models\ProcurementRequest;
use App\Models\ProcurementRequestDetail;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Realisasi pengadaan, dari satu usulan yang sudah disetujui maupun langsung
 * tanpa usulan sama sekali (ADR 0031).
 *
 * Dua bentuk baris: baris usulan mengirim `procurement_request_detail_id`
 * (item_id diturunkan server-side lewat `rincianDisetujui()`), baris tambahan
 * mengirim `item_id` langsung — Pejabat Pengadaan boleh menambah barang di
 * luar usulan, dan jumlah pesan boleh melebihi jumlah yang disetujui (ADR
 * 0029). Yang tetap wajib selama usulannya ada: seluruh rincian usulan yang
 * disetujui harus tercakup, tidak boleh ada yang hilang dari kiriman.
 *
 * Realisasi langsung adalah kasus batas dari bentuk yang sama — seluruh
 * barisnya baris tambahan, aturan cakupan tidak punya apa pun untuk ditagih.
 * Gantinya ia wajib membawa dasarnya sendiri: `basis_reason` dan `basis_note`.
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
     * Usulan yang dikirim kosong disamakan dengan tidak dikirim, supaya
     * formulir yang menyertakan input tersembunyi bernilai kosong tidak jatuh
     * ke aturan `exists` dan menghasilkan galat yang membingungkan.
     */
    protected function prepareForValidation(): void
    {
        if (trim((string) $this->input('procurement_request_id', '')) === '') {
            $this->merge(['procurement_request_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'procurement_request_id' => [
                'nullable',
                Rule::exists('procurement_requests', 'procurement_request_id'),
            ],

            // Dasar pengadaan wajib justru ketika usulannya tidak ada: tanpa
            // itu Kasubbag Umum dan TI tidak punya bahan penilaian apa pun
            // selain penyedia, barang, dan harga (ADR 0031).
            'basis_reason' => [
                'required_without:procurement_request_id',
                'nullable',
                Rule::enum(AlasanPengadaan::class),
            ],
            'basis_note' => [
                'required_without:procurement_request_id',
                'nullable',
                'string',
                'max:500',
            ],
            'supplier_id' => [
                'required',
                Rule::exists('suppliers', 'supplier_id')->where('is_active', true),
            ],
            'procurement_date' => ['required', 'date', 'before_or_equal:today'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.procurement_request_detail_id' => ['nullable', 'distinct'],
            'items.*.item_id' => ['nullable', 'distinct', Rule::exists('items', 'item_id')],
            'items.*.amount' => ['required', 'integer', 'min:1', 'max:999999'],
            'items.*.unit_price' => ['required', 'integer', 'min:0', 'max:999999999999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $procurementRequest = $this->usulan();

            // Usulan dikirim tetapi tidak ditemukan — aturan `exists` sudah
            // mengeluhkannya, tidak perlu diperiksa dua kali. Berbeda dari
            // usulan yang memang tidak dikirim: itu realisasi langsung, dan
            // pemeriksaan barisnya di bawah tetap harus jalan.
            if ($this->punyaUsulan() && $procurementRequest === null) {
                return;
            }

            if ($procurementRequest !== null) {
                if ($procurementRequest->isDisetujuiSelesai()) {
                    $validator->errors()->add('procurement_request_id', 'Usulan ini sudah memiliki realisasi.');

                    return;
                }

                if (! $procurementRequest->isDisetujuiMenungguRealisasi()) {
                    $validator->errors()->add('procurement_request_id', 'Realisasi hanya dapat dibuat dari usulan yang sudah disetujui.');

                    return;
                }
            }

            // Kosong pada realisasi langsung, sehingga aturan cakupan di bawah
            // tidak punya apa pun untuk ditagih.
            $rincian = $this->rincianDisetujui();
            $usulanTerkirim = [];
            $itemTerpakai = [];

            foreach ((array) $this->input('items', []) as $index => $baris) {
                $detailId = trim((string) ($baris['procurement_request_detail_id'] ?? ''));

                if ($detailId !== '') {
                    if ($procurementRequest === null) {
                        $validator->errors()->add(
                            "items.{$index}.procurement_request_detail_id",
                            'Realisasi langsung tidak boleh memuat barang dari usulan.',
                        );

                        continue;
                    }

                    $detail = $rincian->get($detailId);

                    if ($detail === null) {
                        $validator->errors()->add(
                            "items.{$index}.procurement_request_detail_id",
                            'Barang tidak ditemukan pada rincian usulan yang disetujui.',
                        );

                        continue;
                    }

                    $usulanTerkirim[] = $detailId;
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

            $belumLengkap = $rincian->keys()->diff($usulanTerkirim);

            if ($belumLengkap->isNotEmpty()) {
                $validator->errors()->add('items', sprintf(
                    'Seluruh barang yang disetujui usulan wajib disertakan. Belum ada: %s.',
                    $rincian->only($belumLengkap->all())
                        ->map(fn (ProcurementRequestDetail $detail) => $detail->item?->item_name ?? 'barang tidak dikenal')
                        ->implode(', '),
                ));
            }
        });
    }

    /**
     * Apakah kiriman ini menyebut sebuah usulan, terlepas dari sah atau tidak.
     */
    private function punyaUsulan(): bool
    {
        return trim((string) $this->input('procurement_request_id', '')) !== '';
    }

    private function usulan(): ?ProcurementRequest
    {
        if (! $this->punyaUsulan()) {
            return null;
        }

        return ProcurementRequest::find((string) $this->input('procurement_request_id'));
    }

    /**
     * Rincian usulan yang disetujui, atau koleksi kosong pada realisasi
     * langsung.
     *
     * Guard-nya eksplisit dan bukan sekadar kehati-hatian: tanpa itu kueri
     * berjalan dengan `procurement_request_id` null, yang oleh Laravel diubah
     * menjadi `where ... is null`. Kebetulan hasilnya kosong karena kolom itu
     * NOT NULL, tetapi bersandar pada kebetulan semacam itu rapuh.
     *
     * @return Collection<string, ProcurementRequestDetail>
     */
    public function rincianDisetujui(): Collection
    {
        if (! $this->punyaUsulan()) {
            return $this->rincianDisetujui ??= new Collection;
        }

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
            'procurement_request_id.exists' => 'Usulan pengadaan tidak ditemukan.',
            'basis_reason.required_without' => 'Pilih dasar pengadaan. Realisasi tanpa usulan wajib menyebutkan dasarnya.',
            'basis_note.required_without' => 'Jelaskan dasar pengadaannya. Keterangan ini yang dinilai Kasubbag Umum dan TI.',
            'items.required' => 'Tambahkan minimal satu barang ke realisasi.',
            'items.min' => 'Tambahkan minimal satu barang ke realisasi.',
            'items.*.amount.min' => 'Jumlah pesan minimal 1.',
        ];
    }
}
