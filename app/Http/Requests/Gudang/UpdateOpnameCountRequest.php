<?php

namespace App\Http\Requests\Gudang;

use App\Enums\AlasanSelisih;
use App\Models\OpnameDetail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Penyimpanan hasil pencacahan secara massal.
 *
 * Boleh sebagian: barang yang belum dicacah dikirim dengan `stock_real` kosong.
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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'details' => ['required', 'array'],
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

                if ($detail === null || ! isset($baris['stock_real']) || $baris['stock_real'] === '') {
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
            'details.required' => 'Tidak ada data pencacahan yang dikirim.',
            'details.*.stock_real.integer' => 'Hasil cacah harus berupa angka bulat.',
            'details.*.stock_real.min' => 'Hasil cacah tidak boleh kurang dari :min.',
            'details.*.adjustment_note.max' => 'Keterangan maksimal :max karakter.',
        ];
    }
}
