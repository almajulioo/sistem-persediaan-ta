<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menutup butir terbuka ADR 0015.
 *
 * Kolom `status` pada rincian usulan tidak pernah punya enum, cast, maupun
 * makna yang tertulis di mana pun — ia warisan desain ERD awal. Setelah
 * ditinjau, isinya duplikat `amount_granted` yang sudah menyatakan hal yang
 * sama dengan lebih tepat:
 *
 * - `null` → belum diputuskan
 * - `0`    → barang ini tidak disetujui
 * - `N`    → disetujui sebanyak N
 *
 * Menyimpan keduanya berarti dua sumber kebenaran yang bisa bertentangan.
 * Status persetujuan tingkat dokumen tetap ada di
 * `procurement_requests.status` (enum ProcurementRequestStatus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_request_details', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_request_details', function (Blueprint $table) {
            $table->string('status')->default('diajukan');
        });
    }
};
