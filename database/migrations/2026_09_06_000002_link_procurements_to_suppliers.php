<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penyedia menjadi relasi, bukan lagi teks bebas.
 *
 * `additional_doc` ikut dibuang: satu kolom string untuk "dokumen tambahan"
 * tidak pernah cukup — dokumen pengadaan berjumlah lebih dari satu dan
 * masing-masing perlu diketahui jenisnya, siapa yang mengunggah, dan kapan.
 * Penggantinya adalah tabel `procurement_documents`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurements', function (Blueprint $table) {
            $table->foreignUuid('supplier_id')
                ->after('procurement_request_id')
                ->constrained('suppliers', 'supplier_id')
                ->restrictOnDelete();

            $table->dropColumn(['supplier', 'additional_doc']);
        });
    }

    public function down(): void
    {
        Schema::table('procurements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');

            $table->string('supplier');
            $table->string('additional_doc')->nullable();
        });
    }
};
