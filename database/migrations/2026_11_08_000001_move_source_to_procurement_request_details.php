<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sumber usulan pengadaan pindah dari dokumen ke barang (ADR 0044).
 *
 * Sejak ADR 0027 satu draf menampung barang dari pemantauan stok, permohonan
 * pengguna, dan tambahan Pengelola Gudang sekaligus, sehingga
 * `procurement_requests.source` hanya mencatat siapa yang kebetulan membuat
 * draf lebih dulu. `reference_type`/`reference_id` ikut dibuang: keduanya
 * hanya bermakna untuk sumber `dari_permintaan` (ADR 0015), tidak pernah
 * diisi kode mana pun, dan asal permohonan sudah ditunjuk per barang lewat
 * `item_application_details.procurement_request_id`.
 *
 * `source` sengaja tanpa nilai bawaan: setiap jalur yang membuat baris wajib
 * menyebut asalnya sendiri, alasan yang sama dengan `audit_logs.module`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_request_details', function (Blueprint $table) {
            $table->string('source')->nullable()->after('item_id');
            $table->text('note')->nullable()->after('source');
        });

        $this->isiSumberBarisLama();

        Schema::table('procurement_request_details', function (Blueprint $table) {
            $table->string('source')->nullable(false)->change();
        });

        Schema::table('procurement_requests', function (Blueprint $table) {
            $table->dropColumn(['source', 'reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::table('procurement_requests', function (Blueprint $table) {
            $table->string('source')->nullable()->after('procurement_request_id');
            $table->string('reference_type')->nullable()->after('source');
            $table->uuid('reference_id')->nullable()->after('reference_type');
        });

        // Satu dokumen hanya muat satu sumber. Yang ditulis belakangan menang,
        // jadi urutannya dari yang paling lemah: pemantauan stok, manual, lalu
        // permohonan pengguna.
        foreach (['pemantauan_stok', 'manual', 'dari_permohonan'] as $sumber) {
            DB::table('procurement_requests')
                ->whereIn('procurement_request_id', fn (Builder $query) => $query
                    ->select('procurement_request_id')
                    ->from('procurement_request_details')
                    ->where('source', $sumber))
                ->update(['source' => $sumber]);
        }

        DB::table('procurement_requests')->whereNull('source')->update(['source' => 'pemantauan_stok']);

        Schema::table('procurement_requests', function (Blueprint $table) {
            $table->string('source')->nullable(false)->change();
        });

        Schema::table('procurement_request_details', function (Blueprint $table) {
            $table->dropColumn(['source', 'note']);
        });
    }

    /**
     * Aturan pertama yang cocok menang, karena setiap langkah hanya mengisi
     * baris yang `source`-nya masih kosong.
     */
    private function isiSumberBarisLama(): void
    {
        $belumTerisi = fn () => DB::table('procurement_request_details')->whereNull('source');
        $dokumenBersumber = fn (string $sumber) => fn (Builder $query) => $query
            ->select('procurement_request_id')
            ->from('procurement_requests')
            ->where('source', $sumber);

        // Ditunjuk baris permohonan pengguna.
        $belumTerisi()
            ->whereExists(fn (Builder $query) => $query
                ->selectRaw('1')
                ->from('item_application_details')
                ->whereColumn('item_application_details.procurement_request_id', 'procurement_request_details.procurement_request_id')
                ->whereColumn('item_application_details.item_id', 'procurement_request_details.item_id'))
            ->update(['source' => 'dari_permohonan']);

        // Formulir manual tidak pernah mengisi `is_auto`, sehingga barisnya
        // ikut bernilai bawaan true. Karena itu diperiksa sebelum `is_auto`.
        $belumTerisi()
            ->whereIn('procurement_request_id', $dokumenBersumber('manual'))
            ->update(['source' => 'manual']);

        // Masih dikelola pemindaian stok minimum.
        $belumTerisi()->where('is_auto', true)->update(['source' => 'pemantauan_stok']);

        // Jalur lama kekurangan stok permintaan, pendahulu permohonan.
        $belumTerisi()
            ->whereIn('procurement_request_id', $dokumenBersumber('dari_permintaan'))
            ->update(['source' => 'dari_permohonan']);

        // Baris draf pemantauan stok yang sudah disimpan Pengelola Gudang tidak
        // dapat lagi dibedakan dari tambahannya sendiri. Asal dokumennya yang
        // paling mungkin, dan itu pula yang selama ini tampil di detailnya.
        $belumTerisi()
            ->whereIn('procurement_request_id', $dokumenBersumber('pemantauan_stok'))
            ->update(['source' => 'pemantauan_stok']);

        $belumTerisi()->update(['source' => 'manual']);
    }
};
