<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token kiriman formulir usulan manual, dibangkitkan sekali tiap formulir
 * ditampilkan (ADR 0045).
 *
 * Tombol Kembali peramban memulihkan formulir yang sudah terkirim lengkap
 * dengan daftar barangnya; mengirimnya lagi dulu melahirkan usulan kembar
 * tanpa batas. Indeks unik membuat kiriman ulang dengan token yang sama
 * ditolak basis data, termasuk dua kiriman yang tiba bersamaan (klik ganda).
 * Nullable karena draf otomatis dan usulan lama tidak punya formulir asal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_requests', function (Blueprint $table) {
            $table->uuid('submission_token')->nullable()->unique()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_requests', function (Blueprint $table) {
            $table->dropUnique(['submission_token']);
            $table->dropColumn('submission_token');
        });
    }
};
