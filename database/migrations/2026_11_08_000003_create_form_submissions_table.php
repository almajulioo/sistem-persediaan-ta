<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Token kiriman sekali pakai untuk semua formulir pembuat dokumen (ADR 0046),
 * menggantikan `procurement_requests.submission_token` (ADR 0045): kolom per
 * tabel tidak mungkin untuk `stock_mutations` dan `item_proposals` yang milik
 * bersama, dan baris mutasi lahir lewat StockLedger lalu tidak boleh diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->uuid('token')->primary();
            $table->uuidMorphs('subject');
            $table->timestamp('created_at')->useCurrent();
        });

        DB::table('form_submissions')->insertUsing(
            ['token', 'subject_type', 'subject_id', 'created_at'],
            DB::table('procurement_requests')
                ->whereNotNull('submission_token')
                ->select('submission_token', DB::raw("'usulan_pengadaan'"), 'procurement_request_id', 'created_at'),
        );

        Schema::table('procurement_requests', function (Blueprint $table) {
            $table->dropUnique(['submission_token']);
            $table->dropColumn('submission_token');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_requests', function (Blueprint $table) {
            $table->uuid('submission_token')->nullable()->unique()->after('status');
        });

        DB::table('form_submissions')
            ->where('subject_type', 'usulan_pengadaan')
            ->orderBy('created_at')
            ->each(fn (object $baris) => DB::table('procurement_requests')
                ->where('procurement_request_id', $baris->subject_id)
                ->update(['submission_token' => $baris->token]));

        Schema::dropIfExists('form_submissions');
    }
};
