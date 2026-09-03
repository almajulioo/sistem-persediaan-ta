<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Melengkapi stok opname dengan alur draf dan identitas periode.
     *
     * ERD asli hanya memuat `opname_date` dan `user_id`. Draf diperlukan karena
     * pencacahan tidak selesai dalam satu duduk, sedangkan periode diperlukan
     * karena laporan diminta per triwulan. Lihat ADR 0010 dan ADR 0012.
     */
    public function up(): void
    {
        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->string('status')->default('draf')->after('opname_date');
            $table->string('period_type')->after('status');
            $table->unsignedSmallInteger('period_year')->after('period_type');
            $table->text('notes')->nullable()->after('period_year');
            $table->timestamp('posted_at')->nullable()->after('notes');

            $table->index(['period_year', 'period_type']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->dropIndex(['period_year', 'period_type']);
            $table->dropColumn(['status', 'period_type', 'period_year', 'notes', 'posted_at']);
        });
    }
};
