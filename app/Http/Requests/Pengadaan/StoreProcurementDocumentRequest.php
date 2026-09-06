<?php

namespace App\Http\Requests\Pengadaan;

use App\Enums\ProcurementDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Unggahan berkas pendukung pengadaan: BAST bertanda tangan hasil pindai, dan
 * SPK/kontrak.
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
            // Hanya format yang benar-benar dipakai: hasil pindai dan berkas
            // cetak. 5 MB cukup untuk pindaian beberapa halaman, dan menahan
            // unggahan yang keliru sebelum menyentuh disk.
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
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
            'file.mimes' => 'Berkas harus berformat PDF, JPG, atau PNG.',
            'file.max' => 'Ukuran berkas maksimal 5 MB.',
            'type.required' => 'Pilih jenis dokumen terlebih dahulu.',
        ];
    }

    public function jenis(): ProcurementDocumentType
    {
        return ProcurementDocumentType::from($this->string('type')->value());
    }
}
