<?php

namespace App\Support;

use App\Enums\MutationType;
use App\Enums\OpnameStatus;
use App\Exceptions\OpnameTidakSiapException;
use App\Models\Item;
use App\Models\OpnameDetail;
use App\Models\StockOpname;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Menerapkan hasil stok opname ke stok barang.
 *
 * Prinsip yang dipegang: **yang dikoreksi opname adalah kesalahan pencatatan
 * yang ditemukan saat mencacah**, yaitu `difference` = `stock_real` dikurangi
 * `stock_stored`.
 *
 * Transaksi yang terjadi setelah pencacahan — penyerahan barang, penerimaan
 * pengadaan, mutasi manual — adalah kejadian sah yang sudah tercatat sendiri di
 * buku mutasi. Penyesuaian opname tidak boleh membatalkannya. Karena itu stok
 * akhir tidak selalu sama dengan `stock_real`; ia sama dengan stok saat posting
 * ditambah selisih yang ditemukan.
 *
 * Bila tidak ada transaksi di antara pencacahan dan posting, keduanya
 * menghasilkan angka yang sama. Bila ada, `stock_at_posting` akan berbeda dari
 * `stock_stored` dan baris itu ditandai pada laporan. Lihat ADR 0010.
 */
class OpnamePoster
{
    /**
     * @throws OpnameTidakSiapException bila opname belum memenuhi syarat
     */
    public static function post(StockOpname $opname, User $actor): void
    {
        self::pastikanSiap($opname);

        DB::transaction(function () use ($opname, $actor) {
            $opname->loadMissing('details.item');

            foreach ($opname->details as $detail) {
                self::terapkan($detail, $opname, $actor);
            }

            $opname->forceFill([
                'status' => OpnameStatus::Selesai,
                'posted_at' => now(),
            ])->save();
        });

        AuditLogger::record($actor, "Memposting {$opname->judul()}");

        // Di luar transaksi, sejajar dengan AuditLogger: notifikasi tidak boleh
        // ikut tertulis bila posting batal, dan kegagalannya tidak boleh
        // menggagalkan posting yang sudah sah.
        NotificationScanner::catatOpnameDiposting($opname, $actor);
    }

    /**
     * @throws OpnameTidakSiapException
     */
    private static function pastikanSiap(StockOpname $opname): void
    {
        if (! $opname->isDraf()) {
            throw OpnameTidakSiapException::bukanDraf();
        }

        $belumDicacah = $opname->details()->whereNull('stock_real')->count();

        if ($belumDicacah > 0) {
            throw OpnameTidakSiapException::masihAdaYangBelumDicacah($belumDicacah);
        }

        $tanpaAlasan = $opname->details()
            ->with('item')
            ->where('difference', '!=', 0)
            ->where(fn ($query) => $query->whereNull('adjustment_reason')->orWhereNull('adjustment_note'))
            ->first();

        if ($tanpaAlasan !== null) {
            throw OpnameTidakSiapException::selisihTanpaAlasan($tanpaAlasan->item->item_code);
        }
    }

    /**
     * Terapkan satu baris rincian.
     */
    private static function terapkan(OpnameDetail $detail, StockOpname $opname, User $actor): void
    {
        // Kunci diambil di sini, sebelum selisih dihitung. StockLedger akan
        // mengunci baris yang sama di dalam transaksi ini juga — aman, karena
        // kuncinya sudah dipegang. Inilah yang menutup celah balapan antara
        // membaca stok dan menerapkan mutasi.
        $item = Item::query()->lockForUpdate()->findOrFail($detail->item_id);

        $stokSaatPosting = $item->stock_current;

        // Yang diterapkan adalah selisih yang ditemukan saat mencacah, bukan
        // jarak antara hasil cacah dan stok saat posting. Memakai yang kedua
        // akan membatalkan transaksi sah yang terjadi setelah pencacahan.
        $penyesuaian = $detail->difference;

        // Barang tanpa selisih tidak menghasilkan mutasi. StockLedger menolak
        // jumlah nol untuk jenis dua arah seperti Penyesuaian.
        if ($penyesuaian !== 0) {
            StockLedger::record(
                item: $item,
                type: MutationType::Penyesuaian,
                amount: $penyesuaian,
                recordedBy: $actor,
                notes: self::keterangan($detail),
                reference: $opname,
                referenceNumber: $opname->judul(),
                date: $opname->opname_date,
            );
        }

        $detail->forceFill([
            'stock_at_posting' => $stokSaatPosting,
            'adjustment_amount' => $penyesuaian,
        ])->save();
    }

    /**
     * Keterangan mutasi dirakit dari alasan baku dan keterangan petugas,
     * sehingga baris di riwayat mutasi dapat dibaca tanpa membuka dokumen
     * opname-nya.
     */
    private static function keterangan(OpnameDetail $detail): string
    {
        if ($detail->adjustment_reason === null) {
            return 'Penyesuaian hasil stok opname.';
        }

        return sprintf(
            '%s — %s',
            $detail->adjustment_reason->label(),
            $detail->adjustment_note,
        );
    }
}
