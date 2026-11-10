<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persetujuan permohonan menjadi tiga tahap: Kepala Unit, Pengelola Gudang,
 * lalu Kasubbag Umum dan TI sebagai keputusan akhir. Nota Dinas permohonan
 * disimpan di sini, bukan di `request_documents`, karena tiap permohonan hanya
 * punya satu dokumen dan tabel itu mewajibkan `request_id`.
 *
 * `decided_by` kini berarti yang mengakhiri alur: Kasubbag saat disetujui,
 * atau siapa pun yang menolak di tahapnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_applications', function (Blueprint $table) {
            $table->string('nd_number')->nullable()->unique()->after('application_number');
            $table->string('nd_verification_hash', 64)->nullable()->unique()->after('nd_number');
            $table->foreignUuid('head_approved_by')
                ->nullable()
                ->after('status')
                ->constrained('users', 'user_id')
                ->restrictOnDelete();
            $table->timestamp('head_approved_at')->nullable()->after('head_approved_by');
            $table->foreignUuid('reviewed_by')
                ->nullable()
                ->after('head_approved_at')
                ->constrained('users', 'user_id')
                ->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });

        // Permohonan yang sudah berjalan sedang menunggu Pengelola Gudang.
        // Posisinya dipertahankan, tidak dimundurkan ke Kepala Unit, karena
        // permohonan lama tidak punya Nota Dinas untuk ditandatangani.
        DB::table('item_applications')
            ->where('status', 'diajukan')
            ->update(['status' => 'disetujui_kepala_unit']);
    }

    public function down(): void
    {
        DB::table('item_applications')
            ->whereIn('status', ['disetujui_kepala_unit', 'ditinjau_gudang'])
            ->update(['status' => 'diajukan']);

        Schema::table('item_applications', function (Blueprint $table) {
            $table->dropForeign(['head_approved_by']);
            $table->dropForeign(['reviewed_by']);
            $table->dropUnique(['nd_number']);
            $table->dropUnique(['nd_verification_hash']);
            $table->dropColumn([
                'nd_number',
                'nd_verification_hash',
                'head_approved_by',
                'head_approved_at',
                'reviewed_by',
                'reviewed_at',
            ]);
        });
    }
};
