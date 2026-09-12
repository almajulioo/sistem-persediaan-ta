<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrasi data, bukan skema — kolom `status` tetap `string` polos.
 *
 * `ProcurementRequestStatus::Disetujui` dipecah menjadi
 * `DisetujuiMenungguRealisasi` dan `DisetujuiSelesai`, dibedakan murni oleh
 * ada/tidaknya baris `procurements` untuk usulan itu, terlepas dari nasib
 * realisasinya (termasuk yang dibatalkan tetap terhitung "selesai"). Baris
 * lama berstatus `disetujui` dibackfill sesuai kenyataan itu. Lihat
 * ADR 0028.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('procurement_requests')
            ->where('status', 'disetujui')
            ->whereIn('procurement_request_id', function ($query) {
                $query->select('procurement_request_id')->from('procurements');
            })
            ->update(['status' => 'disetujui_selesai']);

        DB::table('procurement_requests')
            ->where('status', 'disetujui')
            ->update(['status' => 'disetujui_menunggu_realisasi']);
    }

    public function down(): void
    {
        DB::table('procurement_requests')
            ->whereIn('status', ['disetujui_menunggu_realisasi', 'disetujui_selesai'])
            ->update(['status' => 'disetujui']);
    }
};
