<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jenjang persetujuan realisasi pengadaan oleh Kasubbag Umum dan TI.
 *
 * Sampai ADR 0029 realisasi langsung berlaku begitu dibuat Pejabat Pengadaan;
 * tidak ada pihak yang menilai penyedia, barang, dan harganya sebelum barang
 * dipesan. Lihat ADR 0030.
 *
 * Satu set kolom menampung keputusan **terakhir**, persetujuan maupun
 * penolakan — statusnya yang membedakan keduanya. Pola yang sama dengan
 * `cancelled_*` di atasnya. Riwayat lengkap tiap keputusan tetap tersimpan di
 * `audit_logs` yang immutable (ADR 0005), jadi menimpa kolom ini saat realisasi
 * ditolak lalu diajukan ulang tidak menghilangkan jejak apa pun.
 *
 * Seluruhnya nullable: realisasi yang lahir sebelum migrasi ini tetap sah tanpa
 * pengisian ulang, dan realisasi yang belum diputuskan memang belum punya
 * peninjau.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurements', function (Blueprint $table) {
            $table->timestamp('reviewed_at')->nullable()->after('status');

            // Sejajar `cancelled_by`: pemberi persetujuan adalah jabatan yang
            // berbeda dari perealisasi, dan pemisahan itu justru intinya.
            $table->foreignUuid('reviewed_by')
                ->nullable()
                ->after('reviewed_at')
                ->constrained('users', 'user_id')
                ->restrictOnDelete();

            // Wajib diisi saat menolak, opsional saat menyetujui — ditegakkan
            // di form request, bukan di basis data, karena satu kolom melayani
            // dua keputusan.
            $table->text('review_note')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('procurements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['reviewed_at', 'review_note']);
        });
    }
};
