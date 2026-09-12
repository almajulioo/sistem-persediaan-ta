<?php

namespace App\Support;

use App\Enums\NotificationType;
use App\Enums\ProcurementRequestSource;
use App\Enums\ProcurementRequestStatus;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\ProcurementRequest;
use App\Models\StockOpname;
use Illuminate\Support\Facades\DB;

/**
 * Menjaga satu draf usulan pengadaan (ProcurementRequestStatus::Draf) tetap
 * mencerminkan barang yang sedang di bawah stok minimum, tanpa menimpa
 * penyuntingan manual Pengelola Gudang.
 *
 * Sifatnya sejajar dengan NotificationScanner: idempoten, dipanggil eksplisit
 * (ADR 0013/0014), aman dijalankan berkali-kali. Bedanya, objek yang dijaga
 * bukan baris notifikasi, melainkan satu dokumen draf tunggal beserta
 * rinciannya. Lihat ADR 0027.
 *
 * Baris rincian yang `is_auto = true` sepenuhnya milik pemindaian ini:
 * jumlahnya ditulis ulang tiap pemindaian, dan barisnya dibuang begitu
 * barangnya pulih. Begitu Pengelola Gudang menyimpan perubahan lewat
 * ProcurementDraftController::update(), seluruh baris yang ia kirim ditandai
 * `is_auto = false` dan sejak itu tidak pernah lagi disentuh pemindaian —
 * baik jumlahnya maupun keberadaannya — walau barangnya kelak pulih.
 */
class ProcurementDraftScanner
{
    /**
     * Keadaan stok satu barang. Dipanggil setelah stok atau ambang batasnya
     * berubah, sejajar dengan NotificationScanner::periksaBarang().
     */
    public static function perbaruiBarang(Item $item): void
    {
        DB::transaction(function () use ($item) {
            $draft = ProcurementRequest::query()->draf()->lockForUpdate()->first();

            if ($item->is_active && $item->diBawahMinimum()) {
                $draft ??= self::buatDraf();
                self::pastikanBaris($draft, $item);
            } elseif ($draft !== null) {
                self::buangBarisOtomatis($draft, $item->item_id);
                self::hapusJikaKosong($draft);
            }
        });

        self::sinkronkanNotifikasi();
    }

    /**
     * Keadaan seluruh barang. Dipanggil perintah `notifikasi:periksa`.
     */
    public static function perbaruiSeluruh(): void
    {
        DB::transaction(function () {
            $draft = ProcurementRequest::query()->draf()->lockForUpdate()->first();

            $dibawahMinimum = Item::query()->aktif()->diBawahMinimum()
                ->get(['item_id', 'stock_current', 'stock_min', 'stock_max']);

            if ($dibawahMinimum->isEmpty()) {
                if ($draft !== null) {
                    $draft->details()->where('is_auto', true)->delete();
                    self::hapusJikaKosong($draft);
                }

                return;
            }

            $draft ??= self::buatDraf();

            foreach ($dibawahMinimum as $item) {
                self::pastikanBaris($draft, $item);
            }

            $draft->details()
                ->where('is_auto', true)
                ->whereNotIn('item_id', $dibawahMinimum->pluck('item_id'))
                ->delete();

            self::hapusJikaKosong($draft->fresh());
        });

        self::sinkronkanNotifikasi();
    }

    /**
     * Barang yang tersentuh posting stok opname. Dipanggil dari
     * OpnamePoster::post(), sejajar dengan
     * NotificationScanner::periksaBarangTerdampak() yang privat di kelas
     * itu — pemindaian ini menghitung sendiri daftar barang terdampak agar
     * kedua scanner tetap tidak saling bergantung.
     */
    public static function perbaruiBarangTerdampak(StockOpname $opname): void
    {
        $terdampak = $opname->details()->where('adjustment_amount', '!=', 0)->pluck('item_id');

        if ($terdampak->isEmpty()) {
            return;
        }

        Item::query()->whereIn('item_id', $terdampak)->each(
            fn (Item $item) => self::perbaruiBarang($item),
        );
    }

    private static function buatDraf(): ProcurementRequest
    {
        return ProcurementRequest::create([
            'user_id' => null,
            'source' => ProcurementRequestSource::PemantauanStok,
            'status' => ProcurementRequestStatus::Draf,
        ]);
    }

    private static function pastikanBaris(ProcurementRequest $draft, Item $item): void
    {
        $baris = $draft->details()->where('item_id', $item->item_id)->first();

        if ($baris === null) {
            $draft->details()->create([
                'item_id' => $item->item_id,
                'amount_request' => self::saranJumlah($item),
                'is_auto' => true,
            ]);

            return;
        }

        if ($baris->is_auto) {
            $baris->forceFill(['amount_request' => self::saranJumlah($item)])->save();
        }
    }

    private static function buangBarisOtomatis(ProcurementRequest $draft, string $itemId): void
    {
        $draft->details()->where('item_id', $itemId)->where('is_auto', true)->delete();
    }

    private static function hapusJikaKosong(ProcurementRequest $draft): void
    {
        if ($draft->details()->doesntExist()) {
            $draft->delete();
        }
    }

    /**
     * `max(stock_max, stock_min) - stock_current`, minimal 1. `stock_max <= 0`
     * berarti tanpa batas atas (lihat Item::stockStatus()), sehingga jatuh
     * kembali ke stock_min sebagai sasaran.
     */
    private static function saranJumlah(Item $item): int
    {
        return max(max($item->stock_max, $item->stock_min) - $item->stock_current, 1);
    }

    /**
     * Selaraskan notifikasi keadaan "draf tersedia". Dipanggil di sini dan
     * dari ProcurementDraftController setiap kali isi draf bisa berubah.
     *
     * Ditulis lewat Notifier langsung, bukan lewat NotificationScanner:
     * docblock kelas itu menegaskan cakupannya "keadaan gudang menjadi
     * notifikasi", bukan keadaan dokumen pengadaan.
     *
     * Kunci dedup sengaja tetap ("aktif"), tidak terikat UUID satu draf
     * tertentu — makna notifikasinya ("ada draf menunggu") bertahan melewati
     * siklus buang-buat ulang draf, dan Notifier::tulisSatu() sudah menulis
     * ulang subjek/tautannya setiap kali dipanggil.
     */
    public static function sinkronkanNotifikasi(): void
    {
        $draft = ProcurementRequest::query()->draf()->withCount('details')->first();
        $jumlah = $draft->details_count ?? 0;

        Notifier::terapkanKeadaan(
            berlaku: $jumlah > 0,
            penerima: Notifier::penerimaBerperan(UserRole::PengelolaGudang, UserRole::Admin),
            type: NotificationType::DraftUsulanTersedia,
            dedupKey: NotificationType::DraftUsulanTersedia->dedupKey('aktif'),
            title: 'Draf usulan pengadaan tersedia',
            body: sprintf(
                '%d barang di bawah stok minimum sudah terkumpul pada draf usulan. Tinjau, sesuaikan jumlahnya, lalu ajukan.',
                $jumlah,
            ),
            actionUrl: route('procurement-requests.draft.edit', absolute: false),
            subject: $draft,
        );
    }
}
