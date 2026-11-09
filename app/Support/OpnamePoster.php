<?php

namespace App\Support;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
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
 *
 * `stock_stored` adalah stok sistem pada waktu barang itu dihitung, bukan saat
 * draf dibuat (ADR 0057): mutasi di antara pembuatan draf dan penghitungan
 * sudah tercermin di dalamnya, jadi tidak muncul sebagai selisih palsu yang
 * lalu diterapkan dua kali.
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

        AuditLogger::record(
            user: $actor,
            activity: "Memposting {$opname->judul()}",
            module: AuditModule::Opname,
            event: AuditEvent::Posting,
            subject: $opname,
        );

        // Di luar transaksi, sejajar dengan AuditLogger: notifikasi tidak boleh
        // ikut tertulis bila posting batal, dan kegagalannya tidak boleh
        // menggagalkan posting yang sudah sah.
        NotificationScanner::catatOpnameDiposting($opname, $actor);
        ProcurementDraftScanner::perbaruiBarangTerdampak($opname);
    }

    /**
     * @throws OpnameTidakSiapException
     */
    private static function pastikanSiap(StockOpname $opname): void
    {
        if (! $opname->isDraf()) {
            throw OpnameTidakSiapException::bukanDraf();
        }

        // Opname berkala wajib memastikan setiap barang secara fisik, jadi
        // barang kosong menghalangi posting (ADR 0010, dipulihkan ADR 0057).
        // Hanya opname insidental yang barang kosongnya mengikuti stok sistem
        // (ADR 0056).
        if (! $opname->bolehKosong()) {
            $kosong = $opname->details()->whereNull('stock_real')->count();

            if ($kosong > 0) {
                throw OpnameTidakSiapException::masihAdaYangBelumDiisi($kosong);
            }
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

        // Barang yang tidak diisi petugas — hanya mungkin pada opname
        // insidental, pastikanSiap() menolak yang lain — dianggap sesuai stok
        // sistem (ADR 0056). Pembanding dan hasil cacahnya disamakan dengan
        // stok saat posting, sehingga selisihnya nol, stok akhirnya tetap stok
        // sistem detik ini, dan "Stok Sistem" di laporan bukan salinan basi
        // dari saat draf dibuat (ADR 0057). `counted_at` sengaja dibiarkan
        // kosong: tidak ada yang menghitungnya, dan itulah penanda baris ini.
        if (! $detail->sudahDicacah()) {
            $detail->forceFill([
                'stock_stored' => $stokSaatPosting,
                'stock_real' => $stokSaatPosting,
                'difference' => 0,
            ]);
        }

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
