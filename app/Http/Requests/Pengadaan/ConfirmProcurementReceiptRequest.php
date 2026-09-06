<?php

namespace App\Http\Requests\Pengadaan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Konfirmasi penerimaan barang pengadaan oleh Pengelola Gudang.
 *
 * `received.*` tidak dibatasi maksimum terhadap jumlah dipesan — penyedia
 * yang mengirim lebih banyak dari pesanan bukan kasus yang dicegah ADR 0017,
 * yang mengatur kekurangan kiriman. Hanya kiriman kurang yang jadi perhatian
 * di sana, dan itu sudah tertangkap lewat `ProcurementDetail::kurangKirim()`.
 */
class ConfirmProcurementReceiptRequest extends FormRequest
{
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
            'bast_number' => ['required', 'string', 'max:100'],
            'received_at' => ['required', 'date', 'before_or_equal:today'],
            'received' => ['required', 'array', 'min:1'],
            'received.*' => ['required', 'integer', 'min:0', 'max:999999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'bast_number' => 'nomor BAST',
            'received_at' => 'tanggal penerimaan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bast_number.required' => 'Nomor BAST wajib diisi.',
            'received_at.before_or_equal' => 'Tanggal penerimaan tidak boleh melebihi hari ini.',
            'received.required' => 'Isi jumlah diterima untuk setiap barang.',
        ];
    }
}
