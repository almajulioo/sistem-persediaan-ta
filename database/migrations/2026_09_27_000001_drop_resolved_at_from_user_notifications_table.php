<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notifikasi yang selesai kini dihapus, bukan ditandai (ADR 0036).
 *
 * Keadaan yang pulih langsung dibuang barisnya, dan peristiwa dibuang tujuh
 * hari setelah terbit. Tidak ada lagi baris yang hidup dalam keadaan "selesai",
 * sehingga `resolved_at` tinggal kolom yang selamanya null — dibiarkan, ia
 * hanya akan menyesatkan pembaca skema.
 *
 * Baris yang sudah selesai dibuang lebih dulu. Tanpa langkah itu, keadaan yang
 * dulu sudah pulih akan tampil kembali sebagai notifikasi yang masih berlaku
 * begitu kolom penandanya hilang — dan yang belum sempat dibaca ikut kembali
 * ke lonceng.
 *
 * Indeksnya ikut diganti: lonceng kini bertanya "milik siapa, sudah dibaca
 * atau belum", bukan lagi "masih aktif atau tidak". Indeks lama dilepas lebih
 * dulu karena SQLite menolak membuang kolom yang masih dipakai sebuah indeks.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('user_notifications')->whereNotNull('resolved_at')->delete();

        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'resolved_at', 'read_at']);
        });

        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropColumn('resolved_at');
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'read_at']);
        });

        Schema::table('user_notifications', function (Blueprint $table) {
            $table->timestamp('resolved_at')->nullable()->after('read_at');
            $table->index(['user_id', 'resolved_at', 'read_at']);
        });
    }
};
