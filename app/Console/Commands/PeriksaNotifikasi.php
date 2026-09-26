<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Support\NotificationScanner;
use App\Support\Notifier;
use App\Support\ProcurementDraftScanner;
use Illuminate\Console\Command;

/**
 * Menyelaraskan notifikasi dengan keadaan gudang yang sebenarnya.
 *
 * Menangkap hal yang tidak terpicu oleh perubahan stok — periode opname yang
 * jatuh tempo, draf yang menggantung, barang yang tidak bergerak — sekaligus
 * menjadi jaring pengaman bagi perubahan stok yang lewat tanpa pemeriksaan
 * reaktif. Aman dijalankan berkali-kali: seluruh pemeriksaan idempoten.
 *
 * Di sini pula keadaan yang sudah lama dibaca tetapi belum pulih dikembalikan
 * ke lonceng (ADR 0037) — sesudah pemeriksaan, supaya yang baru saja pulih
 * sudah terhapus lebih dulu dan tidak ikut ditagih.
 */
class PeriksaNotifikasi extends Command
{
    protected $signature = 'notifikasi:periksa
                            {--bersihkan : Ikut membuang peristiwa yang berumur lebih dari 7 hari}';

    protected $description = 'Memeriksa keadaan gudang dan menyelaraskan notifikasi dalam aplikasi';

    public function handle(): int
    {
        $this->info('Memeriksa keadaan gudang…');

        NotificationScanner::periksaSemua();
        ProcurementDraftScanner::perbaruiSeluruh();

        $diingatkan = Notifier::ingatkanUlang();
        $this->line("Keadaan yang diingatkan ulang: {$diingatkan}");

        if ($this->option('bersihkan')) {
            $dibuang = Notifier::bersihkan();
            $this->line("Peristiwa lama dibuang: {$dibuang}");
        }

        $tersimpan = Notification::query()->count();
        $this->line("Notifikasi tersimpan setelah pemeriksaan: {$tersimpan}");

        $this->info('Selesai.');

        return self::SUCCESS;
    }
}
