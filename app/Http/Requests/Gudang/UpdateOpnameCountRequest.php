<?php

namespace App\Http\Requests\Gudang;

use App\Enums\AlasanSelisih;
use App\Models\OpnameDetail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Penyimpanan hasil pencacahan, sekaligus posting bila `aksi=posting`.
 *
 * Hanya baris yang berubah yang dikirim; baris yang tidak dikirim tetap
 * seperti tersimpan. Barang yang dikosongkan dikirim dengan `stock_real`
 * kosong dan akan mengikuti stok sistem saat diposting (ADR 0056).
 *
 * Layar pencacahan mengirim barisnya sebagai satu JSON di `hasil_cacah`,
 * bukan ribuan isian `details[...]`: `max_input_vars` PHP (bawaan 1000)
 * memotong isian di atas batas itu diam-diam, dan baris yang terpotong akan
 * ikut diposting sebagai "sesuai". `details` sebagai larik biasa tetap
 * diterima.
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
            'details' => ['sometimes', 'array'],
            'details.*' => ['array'],
            'details.*.stock_real' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'details.*.adjustment_reason' => ['nullable', Rule::enum(AlasanSelisih::class)],
            'details.*.adjustment_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Alasan dan keterangan wajib diisi bila hasil cacah berbeda dari stok
     * tercatat.
     *
     * `stock_stored` dibaca dari basis data, bukan dari request, supaya tidak
     * dapat dipalsukan untuk melewati kewajiban ini.
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

            foreach ((array) $this->input('details', []) as $id => $baris) {
                $detail = $this->details()->get((string) $id);

                if ($detail === null || ! is_array($baris) || ! isset($baris['stock_real']) || $baris['stock_real'] === '') {
                    continue;
                }

                if ((int) $baris['stock_real'] === $detail->stock_stored) {
                    continue;
                }

                $barang = $detail->item->item_code;

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
            'details.array' => 'Data pencacahan tidak terbaca. Muat ulang halaman lalu coba lagi.',
            'details.*.array' => 'Data pencacahan tidak terbaca. Muat ulang halaman lalu coba lagi.',
            'details.*.stock_real.integer' => 'Hasil cacah harus berupa angka bulat.',
            'details.*.stock_real.min' => 'Hasil cacah tidak boleh kurang dari :min.',
            'details.*.stock_real.max' => 'Hasil cacah tidak boleh lebih dari :max.',
            'details.*.adjustment_note.max' => 'Keterangan maksimal :max karakter.',
        ];
    }
}
