<?php

namespace App\Http\Requests\Pengadaan;

use App\Enums\ProcurementDocumentType;
use App\Models\Procurement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Menandai realisasi pengadaan sudah dibelanjakan, beserta foto buktinya.
 *
 * Foto **wajib** minimal satu, tetapi syaratnya dihitung dari keadaan, bukan
 * dari kiriman ini saja: foto belanja yang sudah diunggah lebih dulu lewat
 * kartu Berkas Pendukung ikut dihitung. Berbeda dari BAST yang boleh menyusul
 * (ADR 0018) karena foto belanja dibuat Pejabat Pengadaan sendiri saat
 * belanja, sehingga sudah ada ketika tombolnya ditekan. Lihat ADR 0035.
 */
class MarkProcurementPurchasedRequest extends FormRequest
{
    /** Batas foto per kiriman; foto berikutnya boleh menyusul lewat Berkas Pendukung. */
    public const MAKS_FOTO = 5;

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
            // Belanja tidak mungkin terjadi sebelum dokumen realisasinya
            // bertanggal, dan tidak mungkin terjadi besok.
            'purchased_at' => [
                'required', 'date', 'before_or_equal:today',
                'after_or_equal:'.$this->procurement()->procurement_date->toDateString(),
            ],
            'photos' => ['nullable', 'array', 'max:'.self::MAKS_FOTO],
            // `mimes`, bukan `image`: aturan `image` tidak mengenal HEIC, format
            // bawaan kamera iPhone. Daftarnya satu sumber dengan unggahan lewat
            // Berkas Pendukung.
            'photos.*' => ['file', 'mimes:'.ProcurementDocumentType::FotoBelanja->formatBerkas(), 'max:10240'],
            'purchase_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (count($this->file('photos') ?? []) > 0 || $this->procurement()->jumlahFotoBelanja() > 0) {
                    return;
                }

                $validator->errors()->add(
                    'photos',
                    'Unggah minimal satu foto belanja — foto barang yang dibeli atau foto kegiatan belanjanya.',
                );
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'purchased_at' => 'tanggal belanja',
            'photos' => 'foto belanja',
            'photos.*' => 'foto belanja',
            'purchase_note' => 'catatan belanja',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'purchased_at.before_or_equal' => 'Tanggal belanja tidak boleh melewati hari ini.',
            'purchased_at.after_or_equal' => 'Tanggal belanja tidak boleh sebelum tanggal realisasi.',
            'photos.max' => 'Paling banyak '.self::MAKS_FOTO.' foto sekaligus; foto lainnya dapat diunggah lewat Berkas Pendukung.',
            'photos.*.mimes' => 'Foto harus berformat JPG, PNG, WEBP, atau HEIC.',
            'photos.*.max' => 'Ukuran tiap foto maksimal 10 MB.',
            'photos.*.uploaded' => 'Foto gagal diunggah. Ukurannya kemungkinan melebihi batas unggah server.',
        ];
    }

    private function procurement(): Procurement
    {
        return $this->route('procurement');
    }
}
