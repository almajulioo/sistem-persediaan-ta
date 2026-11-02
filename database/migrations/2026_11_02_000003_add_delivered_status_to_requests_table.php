<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permintaan tidak lagi ditutup oleh yang menyerahkan barang. Setelah Gudang
 * menyerahkan, permintaan berstatus `delivered` sampai pemohon mengonfirmasi
 * penerimaannya (atau ditutup petugas atas namanya setelah batas waktu).
 * Stok tetap keluar saat diserahkan; `delivered_at` menjadi patokan batas
 * waktu konfirmasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->enum('status', ['submitted', 'approved_by_head', 'processed_by_staff', 'in_issuance', 'delivered', 'completed', 'rejected', 'merged'])
                ->default('submitted')
                ->change();
            $table->timestamp('delivered_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        // Sebelum migrasi ini, permintaan yang diserahkan langsung selesai.
        DB::table('requests')->where('status', 'delivered')->update(['status' => 'completed']);

        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn('delivered_at');
            $table->enum('status', ['submitted', 'approved_by_head', 'processed_by_staff', 'in_issuance', 'completed', 'rejected', 'merged'])
                ->default('submitted')
                ->change();
        });
    }
};
