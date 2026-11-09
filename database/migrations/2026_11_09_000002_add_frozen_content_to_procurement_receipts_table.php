<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Isi BAST yang dibekukan saat TTE pertama dibubuhkan (ADR 0051).
 *
 * Setelah ada tanda tangan, BAST tidak boleh ikut berubah hanya karena nama
 * barang di master diganti atau jabatan penanda tangan diperbarui. Karena itu
 * isinya disalin sebagai JSON, dan sidik SHA-256-nya disimpan di sampingnya.
 * Setiap TTE mencatat sidik yang ia tandatangani. Keduanya dikosongkan lagi
 * bila TTE dicabut karena datanya diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_receipts', function (Blueprint $table) {
            $table->json('content')->nullable()->after('notes');
            $table->char('content_hash', 64)->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_receipts', function (Blueprint $table) {
            $table->dropColumn(['content', 'content_hash']);
        });
    }
};
