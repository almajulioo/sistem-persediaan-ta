<?php

namespace App\Http\Requests\Pengadaan;

use App\Enums\ProcurementDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Unggahan berkas pendukung pengadaan: BAST bertanda tangan hasil pindai,
 * SPK/kontrak, dan foto belanja (ADR 0035).
 *
 * Diizinkan bagi kedua peran pengadaan lewat gate `akses-pengadaan`, bukan
 * dibagi per jenis berkas. Alasannya: pemisahan tugas ADR 0016 mengatur siapa
 * yang **memutuskan** — mengusulkan, merealisasi, mengonfirmasi diterima —
 * bukan siapa yang melampirkan buktinya. Yang memegang BAST bertanda tangan
 * adalah gudang, yang memegang kontrak adalah Pejabat Pengadaan, dan keduanya
 * mengunggah lewat satu jalur yang sama.
 */
class StoreProcurementDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('akses-pengadaan') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ProcurementDocumentType::class)],
            // Hasil pindai, berkas cetak, dan foto dari ponsel — termasuk WEBP
            // dan HEIC (bawaan kamera iPhone) sejak foto belanja ADR 0035.
            // Formatnya bergantung jenis dokumen; lihat formatBerkas(). 10 MB
            // karena foto ponsel lazimnya 3-8 MB.
            'file' => ['required', 'file', 'mimes:'.$this->jenisDikirim()->formatBerkas(), 'max:10240'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => 'jenis dokumen',
            'file' => 'berkas',
            'notes' => 'keterangan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Pilih berkas yang akan diunggah.',
            'file.mimes' => $this->jenisDikirim() === ProcurementDocumentType::FotoBelanja
                ? 'Foto belanja harus berformat JPG, PNG, WEBP, atau HEIC.'
                : 'Berkas harus berformat PDF, JPG, PNG, WEBP, atau HEIC.',
            'file.max' => 'Ukuran berkas maksimal 10 MB.',
            'file.uploaded' => 'Berkas gagal diunggah. Ukurannya kemungkinan melebihi batas unggah server.',
            'type.required' => 'Pilih jenis dokumen terlebih dahulu.',
        ];
    }

    public function jenis(): ProcurementDocumentType
    {
        return ProcurementDocumentType::from($this->string('type')->value());
    }

    /**
     * Jenis yang dikirim, dibaca sebelum validasi untuk memilih format berkas.
     * Jenis yang tidak dikenal jatuh ke format umum — aturan `type` sendiri
     * yang menolaknya.
     */
    private function jenisDikirim(): ProcurementDocumentType
    {
        return ProcurementDocumentType::tryFrom((string) $this->input('type')) ?? ProcurementDocumentType::Bast;
    }
}
