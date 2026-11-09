<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jenis notifikasi "Opname Siap Diposting" dihapus (ADR 0056).
 *
 * Sejak barang yang tidak diisi mengikuti stok sistem, setiap draf opname
 * selalu dapat diposting, jadi keadaan "seluruh barang sudah dicacah" tidak
 * lagi bermakna. Barisnya dibuang lebih dulu: `user_notifications.type`
 * di-cast ke enum NotificationType, dan nilai yang tidak lagi dikenal enum
 * akan menggagalkan setiap halaman yang menampilkan lonceng.
 *
 * Tidak dapat dikembalikan — keadaannya memang tidak ada lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('user_notifications')->where('type', 'opname_siap_diposting')->delete();
    }

    public function down(): void
    {
        //
    }
};
