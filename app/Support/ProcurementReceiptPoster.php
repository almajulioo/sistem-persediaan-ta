<?php

namespace App\Support;

use App\Enums\MutationType;
use App\Enums\ProcurementStatus;
use App\Exceptions\ProcurementTidakSiapException;
use App\Models\Item;
use App\Models\Procurement;
use App\Models\ProcurementDetail;
use App\Models\ProcurementReceipt;
use App\Models\User;

/**
 * Memasukkan barang pengadaan ke stok lewat StockLedger, begitu BAST-nya
 * ditandatangani kedua pihak (ADR 0051).
 *
 * Prinsip yang dipegang — ADR 0017: **stok bertambah sebanyak yang
 * benar-benar datang, bukan `amount` yang dipesan**. Jumlahnya diambil dari
 * isi BAST yang dibekukan saat TTE pertama, bukan dari kolom hidup: yang masuk
 * ke stok harus persis yang ditandatangani kedua pihak.
 *
 * Referensi mutasi menunjuk `Procurement` (alias morph map `pengadaan`), dan
 * nomor referensinya **nomor BAST**: sejak ADR 0051 nomornya sudah ada dan
 * terkunci oleh TTE sebelum mutasi tercatat, sehingga alasan ADR 0042 memakai
 * judul realisasi tidak berlaku lagi.
 *
 * Dua bagian, karena dua sisi transaksi:
 * - `posting()` di dalam transaksi penandatanganan yang sudah mengunci
 *   realisasinya;
 * - `setelahPosting()` sesudahnya: notifikasi tidak boleh tertulis bila
 *   transaksinya batal, dan kegagalannya tidak boleh menggagalkan penerimaan
 *   yang sudah sah.
 *
 * Audit ditulis pemanggilnya, satu baris untuk satu aksi.
 */
class ProcurementReceiptPoster
{
    /**
     * @throws ProcurementTidakSiapException bila isi BAST tidak mencakup seluruh barang
     */
    public static function posting(Procurement $procurement, ProcurementReceipt $receipt, User $petugas): void
    {
        $procurement->loadMissing('details', 'supplier');

        $jumlah = collect($receipt->content['rincian'] ?? [])
            ->mapWithKeys(fn (array $baris) => [$baris['id'] => $baris['diterima']]);

        foreach ($procurement->details as $detail) {
            if (! $jumlah->has($detail->procurement_detail_id) || $jumlah[$detail->procurement_detail_id] === null) {
                throw ProcurementTidakSiapException::isiTidakUtuh();
            }

            self::terapkan($detail, (int) $jumlah[$detail->procurement_detail_id], $procurement, $receipt, $petugas);
        }

        $procurement->forceFill(['status' => ProcurementStatus::Diterima])->save();
    }

    /**
     * Pemeriksaan yang menyusul posting. Dipanggil setelah transaksinya
     * selesai.
     */
    public static function setelahPosting(Procurement $procurement, User $pelaku): void
    {
        $procurement = $procurement->fresh(['supplier', 'details.item', 'receipt.signatures', 'user']);

        NotificationScanner::catatPenerimaanDikonfirmasi($procurement, $pelaku);

        // Stok baru masuk, jadi keadaan StokHabis/StokMenipis barang-barang ini
        // harus tertutup seketika, bukan menunggu sapuan pagi (aturan 7;
        // menutup butir terbuka 18).
        foreach ($procurement->details as $detail) {
            if ($detail->item !== null) {
                NotificationScanner::periksaBarang($detail->item);
            }
        }

        // Siklusnya selesai: barang yang datang kurang dan stoknya masih di
        // bawah minimum kembali ke draf (ADR 0047).
        ProcurementDraftScanner::perbaruiBarangDokumen($procurement->details->pluck('item_id'));
    }

    private static function terapkan(
        ProcurementDetail $detail,
        int $jumlahDiterima,
        Procurement $procurement,
        ProcurementReceipt $receipt,
        User $petugas,
    ): void {
        // Kunci diambil di sini, sejalan dengan pola OpnamePoster: StockLedger
        // akan mengunci baris yang sama di dalam transaksi ini juga — aman,
        // karena kuncinya sudah dipegang.
        $item = Item::query()->lockForUpdate()->findOrFail($detail->item_id);

        // Baris dengan jumlah diterima nol tidak menghasilkan mutasi.
        // StockLedger menolak jumlah nol untuk jenis satu arah seperti Masuk.
        if ($jumlahDiterima > 0) {
            StockLedger::record(
                item: $item,
                type: MutationType::Masuk,
                amount: $jumlahDiterima,
                recordedBy: $petugas,
                notes: sprintf(
                    'Penerimaan barang dari %s sesuai BAST %s (%s).',
                    $procurement->supplier?->supplier_name ?? 'penyedia tidak diketahui',
                    $receipt->bast_number,
                    $procurement->judul(),
                ),
                reference: $procurement,
                referenceNumber: $receipt->bast_number,
                date: $receipt->received_at,
            );
        }

        $detail->forceFill([
            'amount_checked' => $jumlahDiterima,
            'amount_received' => $jumlahDiterima,
        ])->save();
    }
}
