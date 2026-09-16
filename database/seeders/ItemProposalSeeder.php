<?php

namespace Database\Seeders;

use App\Enums\ItemProposalStatus;
use App\Enums\SatuanBarang;
use App\Models\Item;
use App\Models\ItemProposal;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Contoh usulan barang baru untuk pengembangan (ADR 0032).
 *
 * Satu usulan sengaja dibiarkan berstatus Diajukan supaya layar pemutus
 * Pengelola Gudang tidak kosong pada `migrate:fresh --seed`, sejalan dengan
 * cara ProcurementSeeder menyisakan satu realisasi di MenungguPersetujuan.
 */
class ItemProposalSeeder extends Seeder
{
    public function run(): void
    {
        $pengadaan = User::where('username', 'pengadaan')->first();
        $gudang = User::where('username', 'gudang')->first();

        if ($pengadaan === null || $gudang === null || ItemProposal::query()->exists()) {
            return;
        }

        // Menunggu keputusan — sekaligus menyalakan notifikasi
        // UsulanBarangMenungguKeputusan bagi akun `gudang`.
        ItemProposal::create([
            'item_name' => 'Spidol Whiteboard Warna Emas',
            'unit' => SatuanBarang::Buah->value,
            'purpose_note' => 'Untuk menandai berkas pemeriksaan pada rapat pleno. Spidol yang tersedia hanya hitam dan merah.',
            'proposed_by' => $pengadaan->user_id,
            'status' => ItemProposalStatus::Diajukan,
        ])->forceFill(['created_at' => now()->subDay()])->save();

        // Sudah disetujui, bertaut ke barang yang benar-benar ada di master.
        $barangTerdaftar = Item::orderBy('item_code')->first();

        if ($barangTerdaftar !== null) {
            ItemProposal::create([
                'item_name' => $barangTerdaftar->item_name,
                'unit' => $barangTerdaftar->unit->value,
                'purpose_note' => 'Kebutuhan rutin administrasi persediaan.',
                'proposed_by' => $pengadaan->user_id,
                'status' => ItemProposalStatus::Diajukan,
            ])->forceFill([
                'status' => ItemProposalStatus::Disetujui,
                'item_id' => $barangTerdaftar->item_id,
                'decided_by' => $gudang->user_id,
                'decided_at' => now()->subDays(20),
                'created_at' => now()->subDays(21),
            ])->save();
        }

        // Ditolak karena duplikat — contoh keterangan keputusan yang menunjuk
        // barang yang sudah ada, yang memang paling sering terjadi.
        ItemProposal::create([
            'item_name' => 'Kertas Fotokopi A4',
            'unit' => SatuanBarang::Rim->value,
            'purpose_note' => 'Untuk cetak laporan hasil pemeriksaan.',
            'proposed_by' => $pengadaan->user_id,
            'status' => ItemProposalStatus::Diajukan,
        ])->forceFill([
            'status' => ItemProposalStatus::Ditolak,
            'decided_by' => $gudang->user_id,
            'decided_at' => now()->subDays(6),
            'decision_note' => 'Barangnya sudah terdaftar sebagai ATK-001 Kertas HVS A4 70 gram. Gunakan kode itu.',
            'created_at' => now()->subDays(7),
        ])->save();

        $this->command?->info('Usulan barang baru contoh berhasil dibuat (1 menunggu keputusan, 1 disetujui, 1 ditolak).');
    }
}
