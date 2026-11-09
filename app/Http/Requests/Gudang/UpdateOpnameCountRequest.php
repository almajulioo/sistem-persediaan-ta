<?php

namespace App\Http\Requests\Gudang;

use App\Enums\AlasanSelisih;
use App\Models\OpnameDetail;
use App\Support\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Penyimpanan hasil pencacahan, sekaligus posting bila `aksi=posting`.
 *
 * Hanya baris yang berubah yang dikirim; baris yang tidak dikirim tetap
 * seperti tersimpan. Barang yang dikosongkan dikirim dengan `stock_real`
 * kosong: pada opname insidental ia mengikuti stok sistem saat diposting
 * (ADR 0056), pada opname lain ia menghalangi posting (ADR 0057).
 *
 * Layar pencacahan mengirim barisnya sebagai satu JSON di `hasil_cacah`,
 * bukan ribuan isian `details[...]`: `max_input_vars` PHP (bawaan 1000)
 * memotong isian di atas batas itu diam-diam. `details` sebagai larik biasa
 * tetap diterima.
 *
 * Petugas mencacah di lembar kertas lalu mengetiknya kemudian, jadi setiap
 * kiriman yang mengubah angka Stok Fisik wajib membawa `dihitung_pada` —
 * tanggal dan jam di lembar itu. Pembanding tiap baris adalah stok sistem
 * pada menit tersebut menurut buku mutasi, bukan stok saat draf dibuat
 * maupun saat disimpan (ADR 0057).
 */
class UpdateOpnameCountRequest extends FormRequest
{
    /**
     * Rincian milik opname ini, dimuat sekali dan dipakai ulang.
     *
     * @var Collection<string, OpnameDetail>|null
     */
    private ?Collection $details = null;

    public function authorize(): bool
    {
        return $this->user()?->can('kelola-gudang') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['dihitung_pada' => trim((string) $this->input('dihitung_pada')) ?: null]);

        if (! $this->has('hasil_cacah')) {
            return;
        }

        $isi = json_decode((string) $this->input('hasil_cacah'), true);

        // Middleware pengubah string kosong menjadi null sudah berjalan
        // sebelum JSON ini dibuka, jadi pembersihannya diulang di sini.
        $this->merge(['details' => is_array($isi)
            ? array_map(
                fn ($baris) => is_array($baris)
                    ? array_map(fn ($nilai) => is_string($nilai) && trim($nilai) === '' ? null : $nilai, $baris)
                    : $baris,
                $isi,
            )
            : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'aksi' => ['nullable', Rule::in(['simpan', 'posting'])],
            'dihitung_pada' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'details' => ['sometimes', 'array'],
            'details.*' => ['array'],
            'details.*.stock_real' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'details.*.stok_sistem' => ['nullable', 'integer'],
            'details.*.adjustment_reason' => ['nullable', Rule::enum(AlasanSelisih::class)],
            'details.*.adjustment_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Waktu cacah, pembanding tiap baris, dan kewajiban alasan.
     *
     * Pembanding dihitung di server dari buku mutasi, tidak pernah diambil
     * dari kiriman — angka kiriman bisa diatur supaya selisih lenyap dan
     * alasan tidak wajib lagi. `stok_sistem` kiriman hanya dicocokkan: bila
     * berbeda (ada mutasi yang tercatat setelah halaman dibuka), barisnya
     * ditolak supaya petugas memeriksa ulang terhadap angka yang benar.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Opname yang sudah diposting ditolak oleh controller dengan pesan
            // yang tepat. Tanpa penjagaan ini, validasi selisih berjalan lebih
            // dulu dan pengguna menerima pesan yang menyesatkan.
            if (! $this->route('opname')->isDraf()) {
                return;
            }

            $berubah = $this->angkaBerubah();

            if ($berubah !== [] && ! $this->waktuMasukAkal($validator)) {
                return;
            }

            $pembanding = $berubah === [] ? [] : $this->pembanding(array_keys($berubah));

            foreach ($this->barisKiriman() as $id => $baris) {
                $detail = $this->details()->get((string) $id);

                if ($detail === null || ! $this->angkaSah($baris)) {
                    continue;
                }

                $barang = $detail->item->item_code;

                if (array_key_exists($id, $berubah)) {
                    $stokSistem = $pembanding[$detail->item_id];
                    $tampil = $baris['stok_sistem'] ?? null;

                    if (filled($tampil) && (int) $tampil !== $stokSistem) {
                        $validator->errors()->add(
                            "details.{$id}.stock_real",
                            sprintf(
                                'Stok sistem %s pada %s ternyata %s, bukan %s. Periksa lagi hasil cacahnya.',
                                $barang,
                                $this->waktuCacah()->translatedFormat('d M Y H:i'),
                                number_format($stokSistem, 0, ',', '.'),
                                number_format((int) $tampil, 0, ',', '.'),
                            ),
                        );

                        continue;
                    }
                } else {
                    $stokSistem = $detail->stock_stored;
                }

                if ((int) $baris['stock_real'] === $stokSistem) {
                    continue;
                }

                if (blank($baris['adjustment_reason'] ?? null)) {
                    $validator->errors()->add(
                        "details.{$id}.adjustment_reason",
                        "Barang {$barang} berselisih, alasan wajib dipilih.",
                    );
                }

                if (blank($baris['adjustment_note'] ?? null)) {
                    $validator->errors()->add(
                        "details.{$id}.adjustment_note",
                        "Barang {$barang} berselisih, keterangan wajib diisi.",
                    );
                }
            }
        });
    }

    public function mauPosting(): bool
    {
        return $this->input('aksi') === 'posting';
    }

    /**
     * Waktu "Dihitung pada" dari lembar cacah, atau null bila kosong atau
     * tidak terbaca.
     */
    public function waktuCacah(): ?CarbonImmutable
    {
        $teks = $this->input('dihitung_pada');

        if (! is_string($teks)) {
            return null;
        }

        $waktu = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $teks, config('app.timezone'));

        return $waktu instanceof CarbonImmutable && $waktu->format('Y-m-d\TH:i') === $teks ? $waktu : null;
    }

    /**
     * Baris kiriman yang angka Stok Fisiknya berubah menjadi angka lain —
     * yang pembandingnya dihitung ulang pada waktu cacah. Baris yang
     * dikosongkan tidak termasuk: ia tidak lagi punya pembanding.
     *
     * @return array<string, int> opname_detail_id => hasil cacah baru
     */
    public function angkaBerubah(): array
    {
        $berubah = [];

        foreach ($this->barisKiriman() as $id => $baris) {
            $detail = $this->details()->get((string) $id);

            if ($detail !== null && $this->angkaSah($baris) && (int) $baris['stock_real'] !== $detail->stock_real) {
                $berubah[(string) $id] = (int) $baris['stock_real'];
            }
        }

        return $berubah;
    }

    /**
     * Stok sistem pada waktu cacah untuk rincian yang disebut.
     *
     * @param  list<string>  $detailIds
     * @return array<string, int> item_id => stok
     */
    public function pembanding(array $detailIds, bool $kunci = false): array
    {
        $waktu = $this->waktuCacah();

        if ($detailIds === [] || $waktu === null) {
            return [];
        }

        return StockLedger::stokPada(
            $this->details()->only($detailIds)->pluck('item_id')->all(),
            $waktu,
            $kunci,
        );
    }

    /**
     * @return Collection<string, OpnameDetail>
     */
    public function details(): Collection
    {
        return $this->details ??= OpnameDetail::query()
            ->with('item')
            ->where('opname_id', $this->route('opname')->opname_id)
            ->get()
            ->keyBy('opname_detail_id');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dihitung_pada.date_format' => 'Dihitung pada tidak terbaca. Isi tanggal dan jam sesuai lembar cacah.',
            'details.array' => 'Data pencacahan tidak terbaca. Muat ulang halaman lalu coba lagi.',
            'details.*.array' => 'Data pencacahan tidak terbaca. Muat ulang halaman lalu coba lagi.',
            'details.*.stock_real.integer' => 'Hasil cacah harus berupa angka bulat.',
            'details.*.stock_real.min' => 'Hasil cacah tidak boleh kurang dari :min.',
            'details.*.stock_real.max' => 'Hasil cacah tidak boleh lebih dari :max.',
            'details.*.stok_sistem.integer' => 'Data pencacahan tidak terbaca. Muat ulang halaman lalu coba lagi.',
            'details.*.adjustment_note.max' => 'Keterangan maksimal :max karakter.',
        ];
    }

    /**
     * Kiriman yang mengubah angka wajib membawa waktu cacah yang masuk akal:
     * tidak sebelum pukul 00:00 tanggal pencacahan — petugas boleh menghitung
     * di kertas sebelum drafnya dibuat — dan tidak melewati saat ini.
     */
    private function waktuMasukAkal(Validator $validator): bool
    {
        if ($validator->errors()->has('dihitung_pada')) {
            return false;
        }

        $waktu = $this->waktuCacah();

        if ($waktu === null) {
            $validator->errors()->add('dihitung_pada', 'Dihitung pada wajib diisi sesuai lembar cacah.');

            return false;
        }

        $awal = $this->route('opname')->opname_date->copy()->startOfDay();

        if ($waktu->lt($awal)) {
            $validator->errors()->add(
                'dihitung_pada',
                'Dihitung pada tidak boleh sebelum tanggal pencacahan, '.$awal->translatedFormat('d F Y').'.',
            );

            return false;
        }

        if ($waktu->gt(now())) {
            $validator->errors()->add('dihitung_pada', 'Dihitung pada tidak boleh melewati waktu sekarang.');

            return false;
        }

        return true;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function barisKiriman(): array
    {
        $details = $this->input('details', []);

        return is_array($details) ? array_filter($details, 'is_array') : [];
    }

    /**
     * Angka bulat tak bertanda dalam batas aturan. Nilai lain sudah ditolak
     * aturan dasar dan tidak perlu dibandingkan.
     *
     * @param  array<string, mixed>  $baris
     */
    private function angkaSah(array $baris): bool
    {
        $nilai = $baris['stock_real'] ?? null;

        return (is_int($nilai) && $nilai >= 0 || is_string($nilai) && ctype_digit($nilai))
            && (int) $nilai <= 999999;
    }
}
