<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menyesuaikan nama kolom dengan konvensi relasi polimorfik Laravel
     * (`reference_id` + `reference_type`), sehingga StockMutation dapat memakai
     * morphTo dan langsung mengembalikan model dokumen sumbernya.
     *
     * Menyimpang dari nama pada ERD (`reference`); dicatat pada ADR 0007.
     */
    public function up(): void
    {
        Schema::table('stock_mutations', function (Blueprint $table) {
            $table->renameColumn('reference', 'reference_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_mutations', function (Blueprint $table) {
            $table->renameColumn('reference_id', 'reference');
        });
    }
};
