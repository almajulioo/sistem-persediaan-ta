<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Melengkapi buku mutasi agar layak jadi jejak audit:
     * siapa yang mencatat, sisa stok setelah mutasi, nomor dokumen terbaca,
     * dan keterangan alasan.
     */
    public function up(): void
    {
        Schema::table('stock_mutations', function (Blueprint $table) {
            // Nullable karena baris hasil seeder/proses sistem tidak punya
            // pencatat, tetapi restrictOnDelete supaya jejak tidak ikut hilang
            // ketika akun pengguna dihapus.
            $table->foreignUuid('user_id')->nullable()->after('item_id')
                ->constrained('users', 'user_id')->restrictOnDelete();

            $table->unsignedInteger('stock_after')->default(0)->after('amount');
            $table->string('reference_number')->nullable()->after('reference_type');
            $table->text('notes')->nullable()->after('reference_number');

            $table->index(['item_id', 'mutation_date']);
        });

        $this->backfillStockAfter();
    }

    public function down(): void
    {
        Schema::table('stock_mutations', function (Blueprint $table) {
            $table->dropIndex(['item_id', 'mutation_date']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['stock_after', 'reference_number', 'notes']);
        });
    }

    /**
     * Isi stock_after untuk baris yang sudah ada dengan saldo berjalan per
     * barang. Tanpa ini, mutasi saldo awal yang tercatat sebelum migrasi ini
     * akan menampilkan 0 pada kartu persediaan.
     */
    private function backfillStockAfter(): void
    {
        DB::table('stock_mutations')
            ->orderBy('item_id')
            ->orderBy('created_at')
            ->orderBy('mutation_id')
            ->get(['mutation_id', 'item_id', 'amount'])
            ->groupBy('item_id')
            ->each(function ($rows) {
                $running = 0;

                foreach ($rows as $row) {
                    $running += (int) $row->amount;

                    DB::table('stock_mutations')
                        ->where('mutation_id', $row->mutation_id)
                        ->update(['stock_after' => max(0, $running)]);
                }
            });
    }
};
