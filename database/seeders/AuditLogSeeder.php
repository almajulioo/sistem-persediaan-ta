<?php

namespace Database\Seeders;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Procurement;
use App\Models\ProcurementRequest;
use App\Models\StockOpname;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Jejak audit contoh.
 *
 * Seeder lain menulis model secara langsung tanpa melewati controller,
 * sehingga tidak satu pun menghasilkan baris `audit_logs`. Tanpa seeder ini
 * layar Log Aktivitas akan kosong sesudah `migrate:fresh --seed` dan tidak ada
 * satu penyaring pun yang dapat diperagakan.
 *
 * Barisnya sengaja dibuat menyebar: beberapa modul, beberapa aksi, seluruh
 * akun, dan rentang tiga puluh hari ke belakang.
 */
class AuditLogSeeder extends Seeder
{
    public function run(): void
    {
        $pengguna = User::all()->keyBy('username');

        if ($pengguna->isEmpty()) {
            return;
        }

        $admin = $pengguna->get('admin');
        $kasubbag = $pengguna->get('kasubbag');
        $gudang = $pengguna->get('gudang');
        $pengadaan = $pengguna->get('pengadaan');

        $barang = Item::query()->inRandomOrder()->limit(8)->get();
        $penyedia = Supplier::query()->inRandomOrder()->limit(4)->get();
        $opname = StockOpname::query()->inRandomOrder()->limit(2)->get();
        $usulan = ProcurementRequest::query()->inRandomOrder()->limit(4)->get();
        $realisasi = Procurement::query()->inRandomOrder()->limit(4)->get();

        $baris = [];

        // Masuk dan keluar sistem: lalu lintas harian yang membuat rekap
        // "Perubahan Data" benar-benar berbeda dari "Total Aktivitas".
        foreach (range(1, 28) as $hari) {
            foreach ($pengguna as $akun) {
                // Tidak semua orang masuk setiap hari.
                if (($hari + crc32($akun->username)) % 3 === 0) {
                    continue;
                }

                $waktu = now()->subDays($hari)->setTime(7, 30)->addMinutes(random_int(0, 90));

                $baris[] = $this->baris($akun, 'Masuk ke sistem', AuditModule::Autentikasi, AuditEvent::Masuk, null, $waktu);
                $baris[] = $this->baris($akun, 'Keluar dari sistem', AuditModule::Autentikasi, AuditEvent::Keluar, null, $waktu->copy()->addHours(random_int(5, 9)));
            }
        }

        foreach ($barang as $i => $item) {
            $baris[] = $this->baris(
                $gudang,
                "Menambah barang: {$item->item_code} - {$item->item_name}",
                AuditModule::Barang,
                AuditEvent::Tambah,
                $item,
                now()->subDays(29 - $i),
            );

            if ($i % 3 === 0) {
                $baris[] = $this->baris(
                    $gudang,
                    "Mengubah barang: {$item->item_code} - {$item->item_name}",
                    AuditModule::Barang,
                    AuditEvent::Ubah,
                    $item,
                    now()->subDays(20 - $i),
                );
            }

            if ($i % 4 === 1) {
                $baris[] = $this->baris(
                    $gudang,
                    "Mencatat mutasi manual Barang Rusak untuk barang {$item->item_code}",
                    AuditModule::Mutasi,
                    AuditEvent::Tambah,
                    $item,
                    now()->subDays(12 - ($i % 6)),
                );
            }
        }

        foreach ($penyedia as $i => $supplier) {
            $baris[] = $this->baris(
                $pengadaan,
                "Menambah penyedia: {$supplier->supplier_name}",
                AuditModule::Penyedia,
                AuditEvent::Tambah,
                $supplier,
                now()->subDays(26 - $i),
            );
        }

        foreach ($opname as $i => $so) {
            $baris[] = $this->baris($gudang, "Membuat draf {$so->judul()}", AuditModule::Opname, AuditEvent::Tambah, $so, now()->subDays(18 - $i));
            $baris[] = $this->baris($gudang, "Mengubah hasil cacah {$so->judul()} (12 dari 20 barang tercacah)", AuditModule::Opname, AuditEvent::Ubah, $so, now()->subDays(17 - $i));
            $baris[] = $this->baris($gudang, "Memposting {$so->judul()}", AuditModule::Opname, AuditEvent::Posting, $so, now()->subDays(16 - $i));
        }

        foreach ($usulan as $i => $u) {
            $baris[] = $this->baris($gudang, "Mengajukan usulan pengadaan: {$u->judul()}", AuditModule::UsulanPengadaan, AuditEvent::Ajukan, $u, now()->subDays(15 - $i));
            $baris[] = $this->baris(
                $pengadaan,
                $i % 4 === 3
                    ? "Menolak usulan pengadaan: {$u->judul()}"
                    : "Menyetujui usulan pengadaan: {$u->judul()}",
                AuditModule::UsulanPengadaan,
                $i % 4 === 3 ? AuditEvent::Tolak : AuditEvent::Setuju,
                $u,
                now()->subDays(14 - $i),
            );
        }

        foreach ($realisasi as $i => $p) {
            $baris[] = $this->baris($pengadaan, "Membuat realisasi pengadaan: {$p->judul()}", AuditModule::Pengadaan, AuditEvent::Tambah, $p, now()->subDays(10 - $i));
            $baris[] = $this->baris($kasubbag, "Menyetujui realisasi pengadaan: {$p->judul()}", AuditModule::Pengadaan, AuditEvent::Setuju, $p, now()->subDays(9 - $i));
            $baris[] = $this->baris($pengadaan, "Mengunggah Kontrak/SPK pada {$p->judul()}", AuditModule::DokumenPengadaan, AuditEvent::Unggah, null, now()->subDays(8 - $i));

            if ($i % 2 === 0) {
                $baris[] = $this->baris($gudang, "Mengunduh Kontrak/SPK (kontrak-{$i}.pdf) pada {$p->judul()}", AuditModule::DokumenPengadaan, AuditEvent::Unduh, null, now()->subDays(7 - $i));
                $baris[] = $this->baris($gudang, "Mengonfirmasi penerimaan {$p->judul()}", AuditModule::Penerimaan, AuditEvent::Terima, $p, now()->subDays(6 - $i));
            }
        }

        $baris[] = $this->baris($admin, 'Menambah akun pengguna: staf.gudang', AuditModule::Pengguna, AuditEvent::Tambah, null, now()->subDays(24));
        $baris[] = $this->baris($admin, 'Menonaktifkan akun pengguna: staf.gudang', AuditModule::Pengguna, AuditEvent::UbahStatus, null, now()->subDays(5));
        $baris[] = $this->baris($admin, 'Menghapus akun pengguna: staf.lama', AuditModule::Pengguna, AuditEvent::Hapus, null, now()->subDays(3));

        // Ditulis sekaligus: satu insert jauh lebih ringan daripada ratusan.
        AuditLog::insert(array_filter($baris));
    }

    /**
     * Satu baris siap-insert. Menghasilkan null bila akunnya tidak ada, supaya
     * seeder tetap jalan pada basis data yang akun contohnya tidak lengkap.
     *
     * @return array<string, mixed>|null
     */
    private function baris(
        ?User $user,
        string $activity,
        AuditModule $module,
        AuditEvent $event,
        mixed $subject,
        \Illuminate\Support\Carbon $waktu,
    ): ?array {
        if ($user === null) {
            return null;
        }

        return [
            'log_id' => (string) Str::uuid(),
            'user_id' => $user->user_id,
            'activity' => $activity,
            'module' => $module->value,
            'event' => $event->value,
            'actor_role' => $user->role->value,
            'ip_address' => '10.10.'.random_int(1, 4).'.'.random_int(10, 250),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'timestamp' => $waktu,
            'created_at' => $waktu,
            'updated_at' => $waktu,
        ];
    }
}
