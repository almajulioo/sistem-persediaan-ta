<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Penjadwalan
|--------------------------------------------------------------------------
| Pemeriksaan notifikasi berjalan tiap pagi sebelum jam kerja, sekalian
| membuang peristiwa yang berumur lebih dari 7 hari (ADR 0036). Perintahnya
| idempoten, sehingga menjalankannya lebih sering atau secara manual tidak
| merusak apa pun.
|
| Di server, penjadwal dijalankan lewat satu entri cron:
|   * * * * * cd /path/proyek && php artisan schedule:run >> /dev/null 2>&1
|
| Bila cron belum tersedia, notifikasi tetap muncul lewat pemeriksaan reaktif
| yang dipanggil saat stok berubah; yang tertunda hanya pemeriksaan berbasis
| waktu — periode opname jatuh tempo, draf menggantung, dan barang tidak
| bergerak. Lihat App\Support\NotificationScanner.
*/
Schedule::command('notifikasi:periksa --bersihkan')
    ->dailyAt('06:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();
