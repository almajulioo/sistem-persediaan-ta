<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom terstruktur pendamping kalimat `activity` (ADR 0034).
 *
 * Seluruhnya aditif dan nullable. `audit_logs` tabel bersama, dan sub sistem
 * permintaan tidak pernah menulis ke sini, sehingga penambahan ini tidak
 * mungkin merusak kode sebelah — tetapi tetap perlu dikabari.
 *
 * `user_id` sengaja tidak disentuh: percobaan masuk gagal tidak dicatat, jadi
 * setiap baris log selalu punya pelaku yang sudah terautentikasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('module', 40)->nullable();
            $table->string('event', 40)->nullable();

            // Cuplikan peran saat aksi dilakukan, bukan peran pengguna saat
            // ini. Peran seseorang bisa berubah, sedangkan jejak audit harus
            // tetap menyatakan kapasitas apa yang ia pakai waktu itu.
            $table->string('actor_role', 40)->nullable();

            $table->string('ip_address', 45)->nullable();

            // Dokumen yang dikenai aksi, polimorfik lewat morph map.
            $table->uuid('subject_id')->nullable();
            $table->string('subject_type')->nullable();

            $table->index('timestamp');
            $table->index(['module', 'timestamp']);
            $table->index('event');
        });

        // Dipisah ke closure kedua: `change()` menulis ulang definisi kolom,
        // tidak dicampur dengan penambahan kolom baru supaya aman di MySQL
        // maupun SQLite. Kalimat aktivitas kini dapat memuat nama berkas
        // unggahan yang panjang.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('activity', 500)->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['timestamp']);
            $table->dropIndex(['module', 'timestamp']);
            $table->dropIndex(['event']);

            $table->dropColumn([
                'module', 'event', 'actor_role', 'ip_address', 'subject_id', 'subject_type',
            ]);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('activity')->change();
        });
    }
};
