<?php

namespace Database\Seeders;

use App\Enums\ProcurementRequestSource;
use App\Enums\ProcurementRequestStatus;
use App\Models\Item;
use App\Models\ProcurementRequest;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProcurementRequestSeeder extends Seeder
{
    /**
     * Contoh usulan pengadaan pada tiga status berbeda, supaya modul dapat
     * langsung dicoba (daftar, detail, persetujuan) tanpa harus mengajukan
     * usulan baru lebih dulu.
     */
    public function run(): void
    {
        $pengaju = User::where('username', 'gudang')->first();

        if ($pengaju === null || ProcurementRequest::query()->exists()) {
            return;
        }

        $this->diajukan($pengaju);
        $this->disetujuiSebagian($pengaju);
        $this->ditolak($pengaju);
        $this->disetujuiPenuh($pengaju);
        $this->disetujuiMenganggur($pengaju);

        $this->command?->info('Usulan pengadaan contoh berhasil dibuat (5 usulan).');
    }

    /**
     * Lahir dari pemantauan stok: ATK-010 sudah habis pada ItemSeeder.
     */
    private function diajukan(User $pengaju): void
    {
        $usulan = ProcurementRequest::create([
            'user_id' => $pengaju->user_id,
            'source' => ProcurementRequestSource::PemantauanStok,
            'status' => ProcurementRequestStatus::Diajukan,
        ]);
        $usulan->forceFill(['created_at' => now()->subDays(2)])->save();

        if ($item = Item::where('item_code', 'ATK-010')->first()) {
            $usulan->details()->create(['item_id' => $item->item_id, 'amount_request' => 80]);
        }
    }

    /**
     * Disetujui sebagian: tinta hitam disetujui penuh, tinta warna dipangkas.
     */
    private function disetujuiSebagian(User $pengaju): void
    {
        $usulan = ProcurementRequest::create([
            'user_id' => $pengaju->user_id,
            'source' => ProcurementRequestSource::Manual,
            'status' => ProcurementRequestStatus::Disetujui,
        ]);
        $usulan->forceFill(['created_at' => now()->subDays(10)])->save();

        if ($item = Item::where('item_code', 'ATK-003')->first()) {
            $usulan->details()->create([
                'item_id' => $item->item_id,
                'amount_request' => 40,
                'amount_granted' => 40,
            ]);
        }

        if ($item = Item::where('item_code', 'ATK-004')->first()) {
            $usulan->details()->create([
                'item_id' => $item->item_id,
                'amount_request' => 40,
                'amount_granted' => 20,
            ]);
        }
    }

    /**
     * Ditolak: stapler dinilai masih cukup.
     */
    private function ditolak(User $pengaju): void
    {
        $usulan = ProcurementRequest::create([
            'user_id' => $pengaju->user_id,
            'source' => ProcurementRequestSource::Manual,
            'status' => ProcurementRequestStatus::Ditolak,
        ]);
        $usulan->forceFill(['created_at' => now()->subDays(5)])->save();

        if ($item = Item::where('item_code', 'ATK-009')->first()) {
            $usulan->details()->create([
                'item_id' => $item->item_id,
                'amount_request' => 10,
                'amount_granted' => 0,
            ]);
        }
    }

    /**
     * Disetujui penuh dan belum direalisasikan — supaya tombol "Realisasikan"
     * (Tahap 3) langsung punya usulan yang bisa dicoba tanpa mengajukan dan
     * menyetujui usulan baru lebih dulu.
     */
    private function disetujuiPenuh(User $pengaju): void
    {
        $usulan = ProcurementRequest::create([
            'user_id' => $pengaju->user_id,
            'source' => ProcurementRequestSource::PemantauanStok,
            'status' => ProcurementRequestStatus::Disetujui,
        ]);
        $usulan->forceFill(['created_at' => now()->subDays(1)])->save();

        if ($item = Item::where('item_code', 'ATK-012')->first()) {
            $usulan->details()->create([
                'item_id' => $item->item_id,
                'amount_request' => 20,
                'amount_granted' => 20,
            ]);
        }
    }

    /**
     * Disetujui sembilan hari lalu dan dibiarkan menganggur — contoh nyata
     * bagi notifikasi keadaan `UsulanBelumDirealisasi` (ambang 7 hari).
     *
     * `updated_at` ikut dimundurkan karena justru kolom itulah yang mewakili
     * kapan usulan diputuskan.
     *
     * Umurnya sengaja sembilan hari, bukan lebih tua daripada usulan
     * "disetujui sebagian" yang sepuluh hari: ProcurementSeeder memilih usulan
     * tertua yang belum direalisasi, dan usulan ini tidak boleh merebut
     * gilirannya.
     */
    private function disetujuiMenganggur(User $pengaju): void
    {
        $usulan = ProcurementRequest::create([
            'user_id' => $pengaju->user_id,
            'source' => ProcurementRequestSource::Manual,
            'status' => ProcurementRequestStatus::Disetujui,
        ]);
        $usulan->forceFill([
            'created_at' => now()->subDays(9),
            'updated_at' => now()->subDays(9),
        ])->save();

        if ($item = Item::where('item_code', 'ATK-007')->first()) {
            $usulan->details()->create([
                'item_id' => $item->item_id,
                'amount_request' => 15,
                'amount_granted' => 15,
            ]);
        }
    }
}
