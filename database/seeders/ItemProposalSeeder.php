<?php

namespace Database\Seeders;

use App\Enums\ItemApplicationStatus;
use App\Enums\ItemApplicationType;
use App\Enums\ItemProposalStatus;
use App\Enums\SatuanBarang;
use App\Models\Item;
use App\Models\ItemApplication;
use App\Models\ItemProposal;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Contoh usulan barang baru untuk pengembangan (ADR 0032).
 *
 * Sejak ADR 0053 usulan barang hanya lahir dari permohonan barang baru yang
 * disetujui, dan diputuskan dari rincian permohonan itu. Karena itu contohnya
 * dibuat sebagai satu permohonan yang sudah disetujui dengan tiga baris barang
 * baru — tanpa permohonan, usulannya tidak punya layar keputusan.
 *
 * Satu usulan sengaja dibiarkan berstatus Diajukan supaya tombol Daftarkan/Tolak
 * Pengelola Gudang tidak kosong pada `migrate:fresh --seed`, sejalan dengan
 * cara ProcurementSeeder menyisakan satu realisasi di MenungguPersetujuan.
 */
class ItemProposalSeeder extends Seeder
{
    public function run(): void
    {
        $pemohon = User::where('username', 'pengadaan')->first();
        $gudang = User::where('username', 'gudang')->first();

        if ($pemohon === null || $gudang === null || ItemProposal::query()->exists()) {
            return;
        }

        $diajukan = now()->subDays(9);
        $disetujui = now()->subDays(8);

        $permohonan = ItemApplication::create([
            'application_number' => 'PMH-'.$diajukan->format('YmdHis').'-CNTH',
            'user_id' => $pemohon->user_id,
            'date' => $diajukan,
            'purpose' => 'Kebutuhan rapat pleno dan cetak laporan hasil pemeriksaan semester II.',
            'status' => ItemApplicationStatus::Diajukan,
        ]);
        $permohonan->forceFill([
            'status' => ItemApplicationStatus::Disetujui,
            'decided_by' => $gudang->user_id,
            'decided_at' => $disetujui,
            'created_at' => $diajukan,
        ])->save();

        // Menunggu keputusan — sekaligus menyalakan notifikasi
        // UsulanBarangMenungguKeputusan bagi akun `gudang`.
        $this->baris($permohonan, $pemohon, $disetujui, [
            'item_name' => 'Spidol Whiteboard Warna Emas',
            'unit' => SatuanBarang::Buah,
            'note' => 'Untuk menandai berkas pemeriksaan pada rapat pleno. Spidol yang tersedia hanya hitam dan merah.',
            'quantity' => 12,
        ]);

        // Sudah disetujui, bertaut ke barang yang benar-benar ada di master.
        $barangTerdaftar = Item::orderBy('item_code')->first();

        if ($barangTerdaftar !== null) {
            $this->baris($permohonan, $pemohon, $disetujui, [
                'item_name' => $barangTerdaftar->item_name,
                'unit' => $barangTerdaftar->unit,
                'note' => 'Kebutuhan rutin administrasi persediaan.',
                'quantity' => 5,
            ])->forceFill([
                'status' => ItemProposalStatus::Disetujui,
                'item_id' => $barangTerdaftar->item_id,
                'decided_by' => $gudang->user_id,
                'decided_at' => now()->subDays(7),
            ])->save();
        }

        // Ditolak karena duplikat — contoh keterangan keputusan yang menunjuk
        // barang yang sudah ada, yang memang paling sering terjadi.
        $this->baris($permohonan, $pemohon, $disetujui, [
            'item_name' => 'Kertas Fotokopi A4',
            'unit' => SatuanBarang::Rim,
            'note' => 'Untuk cetak laporan hasil pemeriksaan.',
            'quantity' => 10,
        ])->forceFill([
            'status' => ItemProposalStatus::Ditolak,
            'decided_by' => $gudang->user_id,
            'decided_at' => now()->subDays(6),
            'decision_note' => 'Barangnya sudah terdaftar sebagai ATK-001 Kertas HVS A4 70 gram. Gunakan kode itu.',
        ])->save();

        $this->command?->info("Permohonan contoh {$permohonan->application_number} berhasil dibuat dengan 3 usulan barang baru (1 menunggu keputusan, 1 disetujui, 1 ditolak).");
    }

    /**
     * Satu baris barang baru beserta usulannya, seperti yang dibuat
     * ItemApplicationWorkflowService::approve(): pengusulnya pemohon.
     *
     * @param  array{item_name: string, unit: SatuanBarang, note: string, quantity: int}  $data
     */
    private function baris(ItemApplication $permohonan, User $pemohon, Carbon $lahir, array $data): ItemProposal
    {
        $usulan = ItemProposal::create([
            'item_name' => $data['item_name'],
            'unit' => $data['unit']->value,
            'purpose_note' => $data['note'],
            'proposed_by' => $pemohon->user_id,
            'status' => ItemProposalStatus::Diajukan,
        ]);
        $usulan->forceFill(['created_at' => $lahir])->save();

        $permohonan->details()->create([
            'type' => ItemApplicationType::BarangBaru,
            'item_name' => $data['item_name'],
            'unit' => $data['unit']->value,
            'note' => $data['note'],
            'quantity' => $data['quantity'],
            'item_proposal_id' => $usulan->item_proposal_id,
        ]);

        return $usulan;
    }
}
