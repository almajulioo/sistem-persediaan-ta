<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menandai apakah satu baris rincian masih dikelola otomatis oleh
 * App\Support\ProcurementDraftScanner (true) atau sudah disentuh Pengelola
 * Gudang lewat halaman draf (false).
 *
 * Hanya bermakna selama procurement_requests.status = Draf. Begitu diajukan,
 * kolom ini tidak lagi dibaca siapa pun — dibiarkan apa adanya sebagai jejak
 * yang tidak berbahaya, sejalan dengan amount_granted yang juga hanya berarti
 * pada tahap tertentu siklus hidup dokumen. Lihat ADR 0027.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_request_details', function (Blueprint $table) {
            $table->boolean('is_auto')->default(true)->after('amount_granted');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_request_details', function (Blueprint $table) {
            $table->dropColumn('is_auto');
        });
    }
};
