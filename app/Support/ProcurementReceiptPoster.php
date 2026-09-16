<?php

namespace App\Support;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\MutationType;
use App\Enums\ProcurementStatus;
use App\Exceptions\ProcurementTidakSiapException;
use App\Models\Item;
use App\Models\Procurement;
use App\Models\ProcurementDetail;
use App\Models\ProcurementReceipt;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Menerapkan konfirmasi penerimaan barang pengadaan: mencatat peristiwa
 * penerimaan sekaligus menambah stok lewat StockLedger.
 *
 * Prinsip yang dipegang — ADR 0017: **stok bertambah sebanyak
 * `amount_received` (yang benar-benar datang), bukan `amount` (yang
 * dipesan)**. Barang yang datang kurang tetap tercatat jujur; kekurangannya
 * terlihat lewat `ProcurementDetail::kurangKirim()`, bukan disamarkan lewat
 * mutasi koreksi.
 *
 * Referensi mutasi menunjuk `Procurement` (alias morph map `pengadaan`),
 * bukan `ProcurementReceipt` — dokumen sumber stok masuk adalah realisasinya,
 * BAST hanya mencatat peristiwa penerimaannya. Lihat ADR 0008 dan ADR 0015.
 */
class ProcurementReceiptPoster
{
    /**
     * @param  array<string, int>  $diterima  Peta procurement_detail_id => jumlah diterima.
     *
     * @throws ProcurementTidakSiapException bila realisasi bukan berstatus MenungguPenerimaan
     */
    public static function post(
        Procurement $procurement,
        array $diterima,
        User $actor,
        string $bastNumber,
        CarbonInterface $receivedAt,
        ?string $notes = null,
    ): ProcurementReceipt {
        self::pastikanSiap($procurement);

        $receipt = DB::transaction(function () use ($procurement, $diterima, $actor, $bastNumber, $receivedAt, $notes) {
            $procurement->loadMissing('details', 'supplier');

            foreach ($procurement->details as $detail) {
                self::terapkan($detail, $diterima, $procurement, $actor, $bastNumber, $receivedAt);
            }

            $procurement->forceFill(['status' => ProcurementStatus::Diterima])->save();

            return ProcurementReceipt::create([
                'procurement_id' => $procurement->procurement_id,
                'bast_number' => $bastNumber,
                'received_by' => $actor->user_id,
                'received_at' => $receivedAt,
                'notes' => $notes,
            ]);
        });

        AuditLogger::record(
            user: $actor,
            activity: "Mengonfirmasi penerimaan {$procurement->judul()}",
            module: AuditModule::Penerimaan,
            event: AuditEvent::Terima,
            subject: $procurement,
        );

        // Di luar transaksi, sejajar dengan AuditLogger dan OpnamePoster:
        // notifikasi tidak boleh ikut tertulis bila konfirmasi batal, dan
        // kegagalannya tidak boleh menggagalkan penerimaan yang sudah sah.
        NotificationScanner::catatPenerimaanDikonfirmasi($procurement->fresh(), $actor);

        return $receipt;
    }

    /**
     * @throws ProcurementTidakSiapException
     */
    private static function pastikanSiap(Procurement $procurement): void
    {
        if (! $procurement->isMenungguPenerimaan()) {
            throw ProcurementTidakSiapException::bukanMenungguPenerimaan();
        }
    }

    /**
     * @param  array<string, int>  $diterima
     */
    private static function terapkan(
        ProcurementDetail $detail,
        array $diterima,
        Procurement $procurement,
        User $actor,
        string $bastNumber,
        CarbonInterface $receivedAt,
    ): void {
        // Kunci diambil di sini, sejalan dengan pola OpnamePoster: StockLedger
        // akan mengunci baris yang sama di dalam transaksi ini juga — aman,
        // karena kuncinya sudah dipegang.
        $item = Item::query()->lockForUpdate()->findOrFail($detail->item_id);

        $jumlahDiterima = (int) ($diterima[$detail->procurement_detail_id] ?? 0);

        // Baris dengan jumlah diterima nol tidak menghasilkan mutasi.
        // StockLedger menolak jumlah nol untuk jenis satu arah seperti Masuk.
        if ($jumlahDiterima > 0) {
            StockLedger::record(
                item: $item,
                type: MutationType::Masuk,
                amount: $jumlahDiterima,
                recordedBy: $actor,
                notes: sprintf(
                    'Penerimaan barang dari %s sesuai BAST %s.',
                    $procurement->supplier?->supplier_name ?? 'penyedia tidak diketahui',
                    $bastNumber,
                ),
                reference: $procurement,
                referenceNumber: $bastNumber,
                date: $receivedAt,
            );
        }

        $detail->forceFill(['amount_received' => $jumlahDiterima])->save();
    }
}
