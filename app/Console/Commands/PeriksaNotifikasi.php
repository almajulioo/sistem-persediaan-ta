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
 */
class PeriksaNotifikasi extends Command
{
    protected $signature = 'notifikasi:periksa
                            {--bersihkan : Ikut membuang notifikasi lama yang sudah selesai dan sudah dibaca}';

    protected $description = 'Memeriksa keadaan gudang dan menyelaraskan notifikasi dalam aplikasi';

    public function handle(): int
    {
        $this->info('Memeriksa keadaan gudang…');

        NotificationScanner::periksaSemua();
        ProcurementDraftScanner::perbaruiSeluruh();

        $aktif = Notification::query()->aktif()->count();
        $this->line("Notifikasi aktif setelah pemeriksaan: {$aktif}");

        if ($this->option('bersihkan')) {
            $dibuang = Notifier::bersihkan();
            $this->line("Notifikasi lama dibuang: {$dibuang}");
        }

        $this->info('Selesai.');

        return self::SUCCESS;
    }
}
