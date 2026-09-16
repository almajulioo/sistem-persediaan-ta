<?php

use App\Enums\AlasanPengadaan;
use App\Enums\AlasanSelisih;
use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\OpnameStatus;
use App\Enums\PeriodeOpname;
use App\Enums\ProcurementStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Procurement;
use App\Models\StockOpname;
use App\Models\Supplier;
use App\Models\User;
use App\Support\DocumentStorage;
use App\Support\ProcurementDraftScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

// Nama pembantu di berkas Pest bersifat global lintas berkas. Seluruh pembantu
// di sini bersufiks `Log` supaya tidak bertabrakan dengan yang sudah ada —
// `bodyOf`, `realisasiDiterima`, dan `seedDraf` semuanya sudah terpakai — dan
// supaya berkas ini tetap dapat dijalankan sendirian.

/**
 * Isi unduhan streaming sebagai teks.
 */
function isiUnduhanLog($response): string
{
    ob_start();
    $response->baseResponse->sendContent();

    return ob_get_clean();
}

/**
 * Kunci primer baris yang sedang ditampilkan layar.
 *
 * Penegasan dilakukan pada `log_id`, bukan pada teks tampilan: nama pengguna
 * dan judul dokumen tidak dijamin unik, sedangkan id selalu unik.
 *
 * @return array<int, string>
 */
function idLog($response): array
{
    return $response->viewData('logs')->pluck('log_id')->all();
}

/**
 * Satu baris log siap pakai, tanpa melewati alur aplikasi.
 */
function catatLog(User $user, array $atribut = []): AuditLog
{
    return AuditLog::create(array_merge([
        'user_id' => $user->user_id,
        'activity' => 'Aktivitas contoh',
        'module' => AuditModule::Barang,
        'event' => AuditEvent::Tambah,
        'actor_role' => $user->role,
        'timestamp' => now(),
    ], $atribut));
}

function penyediaPayloadLog(array $overrides = []): array
{
    return array_merge([
        'supplier_name' => 'CV Sumber Rejeki Lampung',
        'address' => 'Jl. Raden Intan No. 45, Bandar Lampung',
        'phone' => '0721-263450',
        'email' => 'sales@sumberrejeki.co.id',
        'contact_person' => 'Budi Santoso',
        'npwp' => '01.234.567.8-321.000',
        'is_active' => 1,
    ], $overrides);
}

/**
 * Draf opname berisi satu barang yang belum dicacah.
 */
function opnameDrafLog(User $petugas, Item $item): StockOpname
{
    $opname = StockOpname::create([
        'opname_date' => now(),
        'user_id' => $petugas->user_id,
        'status' => OpnameStatus::Draf,
        'period_type' => PeriodeOpname::TriwulanIII,
        'period_year' => now()->year,
    ]);

    $opname->details()->create([
        'item_id' => $item->item_id,
        'stock_stored' => $item->stock_current,
    ]);

    return $opname->fresh('details');
}

/**
 * Barang habis yang menyalakan draf usulan otomatis.
 */
function barangDrafLog(): Item
{
    $item = Item::factory()->habis()->create();
    ProcurementDraftScanner::perbaruiSeluruh();

    return $item;
}

/**
 * Realisasi minimum, cukup untuk dilampiri berkas.
 */
function realisasiBerkasLog(User $pejabat): Procurement
{
    return Procurement::create([
        'supplier_id' => Supplier::factory()->create()->supplier_id,
        'procurement_date' => now(),
        'basis_reason' => AlasanPengadaan::KebutuhanMendesak,
        'basis_note' => 'Kebutuhan mendesak untuk keperluan uji.',
        'status' => ProcurementStatus::MenungguPenerimaan,
        'user_id' => $pejabat->user_id,
    ]);
}

describe('hak akses log aktivitas', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        $this->get('/log-aktivitas')->assertRedirect('/login');
        $this->get('/log-aktivitas/ekspor/csv')->assertRedirect('/login');
    });

    it('menolak seluruh peran selain Admin', function () {
        foreach ([UserRole::PengelolaGudang, UserRole::PejabatPengadaan, UserRole::KasubbagUmumTi] as $role) {
            actingAs(User::factory()->role($role)->create())
                ->get('/log-aktivitas')
                ->assertForbidden();

            actingAs(User::factory()->role($role)->create())
                ->get('/log-aktivitas/ekspor/csv')
                ->assertForbidden();
        }
    });

    it('mengizinkan Admin membuka daftar', function () {
        actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/log-aktivitas')
            ->assertOk()
            ->assertSee('Log Aktivitas');
    });

    it('tidak menyediakan rute tulis apa pun', function () {
        // Baris audit bersifat append-only: tidak ada jalan mengubah maupun
        // menghapusnya lewat HTTP.
        $pelaku = User::factory()->role(UserRole::Admin)->create();
        $log = catatLog($pelaku);

        actingAs($pelaku)->post('/log-aktivitas')->assertStatus(405);
        actingAs($pelaku)->delete("/log-aktivitas/{$log->log_id}")->assertNotFound();

        expect(AuditLog::whereKey($log->log_id)->exists())->toBeTrue();
    });
});

describe('kolom terstruktur', function () {
    it('mengisi modul, aksi, peran, dan subjek dari aksi sungguhan', function () {
        $pejabat = User::factory()->role(UserRole::PejabatPengadaan)->create();

        actingAs($pejabat)->post('/penyedia', penyediaPayloadLog())->assertRedirect('/penyedia');

        $supplier = Supplier::where('supplier_name', 'CV Sumber Rejeki Lampung')->firstOrFail();
        $log = AuditLog::where('user_id', $pejabat->user_id)->latest('timestamp')->firstOrFail();

        expect($log->module)->toBe(AuditModule::Penyedia)
            ->and($log->event)->toBe(AuditEvent::Tambah)
            ->and($log->actor_role)->toBe(UserRole::PejabatPengadaan)
            ->and($log->subject_type)->toBe('penyedia')
            ->and($log->subject_id)->toBe($supplier->supplier_id)
            ->and($log->activity)->toContain('CV Sumber Rejeki Lampung');
    });

    it('menyimpan peran sebagai cuplikan, bukan turunan relasi', function () {
        $pejabat = User::factory()->role(UserRole::PejabatPengadaan)->create();

        actingAs($pejabat)->post('/penyedia', penyediaPayloadLog());

        $log = AuditLog::where('user_id', $pejabat->user_id)->latest('timestamp')->firstOrFail();

        // Peran pelaku naik sesudah aksinya dilakukan.
        $pejabat->forceFill(['role' => UserRole::Admin])->save();

        expect($log->fresh()->actor_role)->toBe(UserRole::PejabatPengadaan)
            ->and($pejabat->fresh()->role)->toBe(UserRole::Admin);
    });

    it('mencatat alamat IP pemanggil', function () {
        $pejabat = User::factory()->role(UserRole::PejabatPengadaan)->create();

        actingAs($pejabat)->post('/penyedia', penyediaPayloadLog());

        expect(AuditLog::where('user_id', $pejabat->user_id)->latest('timestamp')->firstOrFail()->ip_address)
            ->not->toBeNull();
    });

    it('tidak menautkan subjek pada aksi hapus', function () {
        $pejabat = User::factory()->role(UserRole::PejabatPengadaan)->create();
        $supplier = Supplier::factory()->create();

        actingAs($pejabat)->delete("/penyedia/{$supplier->supplier_id}");

        $log = AuditLog::where('event', AuditEvent::Hapus)->latest('timestamp')->firstOrFail();

        expect($log->subject_id)->toBeNull()
            ->and($log->tautanSubjek())->toBeNull();
    });

    it('menghasilkan tautan menuju dokumen yang masih ada', function () {
        $petugas = User::factory()->role(UserRole::PengelolaGudang)->create();
        $item = Item::factory()->create();

        $log = catatLog($petugas, ['subject_type' => 'barang', 'subject_id' => $item->item_id]);

        expect($log->load('subject')->tautanSubjek())->toBe(route('items.show', $item));
    });
});

describe('celah pencatatan yang ditutup', function () {
    it('mencatat pengisian hasil cacah opname', function () {
        $petugas = User::factory()->role(UserRole::PengelolaGudang)->create();
        $item = Item::factory()->create(['stock_current' => 10]);
        $opname = opnameDrafLog($petugas, $item);
        $detail = $opname->details->first();

        actingAs($petugas)->put("/opname/{$opname->opname_id}", [
            'details' => [
                $detail->opname_detail_id => [
                    'stock_real' => 8,
                    'adjustment_reason' => AlasanSelisih::BarangRusak->value,
                    'adjustment_note' => 'Dua rim basah terkena bocoran atap.',
                ],
            ],
        ])->assertRedirect();

        $log = AuditLog::where('module', AuditModule::Opname)
            ->where('event', AuditEvent::Ubah)
            ->latest('timestamp')
            ->firstOrFail();

        expect($log->activity)->toContain('Mengubah hasil cacah')
            ->and($log->activity)->toContain('1 dari 1 barang tercacah')
            ->and($log->subject_id)->toBe($opname->opname_id)
            ->and($log->actor_role)->toBe(UserRole::PengelolaGudang);
    });

    it('mencatat perubahan draf usulan pengadaan', function () {
        $petugas = User::factory()->role(UserRole::PengelolaGudang)->create();
        $item = barangDrafLog();

        actingAs($petugas)->put('/usulan-pengadaan/draf', [
            'items' => [['item_id' => $item->item_id, 'amount_request' => 25]],
        ])->assertRedirect();

        $log = AuditLog::where('module', AuditModule::UsulanPengadaan)
            ->where('event', AuditEvent::Ubah)
            ->latest('timestamp')
            ->firstOrFail();

        expect($log->activity)->toContain('Mengubah draf usulan pengadaan');
    });

    it('mencatat draf yang dikosongkan sebagai penghapusan', function () {
        $petugas = User::factory()->role(UserRole::PengelolaGudang)->create();
        barangDrafLog();

        actingAs($petugas)->put('/usulan-pengadaan/draf', ['items' => []])->assertRedirect();

        $log = AuditLog::where('module', AuditModule::UsulanPengadaan)
            ->latest('timestamp')
            ->firstOrFail();

        expect($log->event)->toBe(AuditEvent::Hapus)
            ->and($log->activity)->toContain('Mengosongkan draf');
    });

    it('mencatat unduhan berkas pengadaan pada disk privat', function () {
        // Berkas uji tidak pernah menyentuh disk sungguhan (ADR 0018).
        Storage::fake(DocumentStorage::DISK);

        $petugas = User::factory()->role(UserRole::PengelolaGudang)->create();
        $pejabat = User::factory()->role(UserRole::PejabatPengadaan)->create();
        $procurement = realisasiBerkasLog($pejabat);

        actingAs($pejabat)->post("/pengadaan/{$procurement->procurement_id}/dokumen", [
            'type' => 'spk_kontrak',
            'file' => UploadedFile::fake()->create('kontrak-final.pdf', 10, 'application/pdf'),
        ])->assertRedirect();

        $document = $procurement->fresh('documents')->documents->firstOrFail();

        actingAs($petugas)
            ->get("/pengadaan/{$procurement->procurement_id}/dokumen/{$document->procurement_document_id}/unduh")
            ->assertOk();

        $log = AuditLog::where('event', AuditEvent::Unduh)->latest('timestamp')->firstOrFail();

        expect($log->module)->toBe(AuditModule::DokumenPengadaan)
            ->and($log->user_id)->toBe($petugas->user_id)
            ->and($log->activity)->toContain('kontrak-final.pdf')
            ->and($log->subject_id)->toBe($document->procurement_document_id);
    });
});

describe('penyaring', function () {
    beforeEach(function () {
        $this->pengelola = User::factory()->role(UserRole::PengelolaGudang)
            ->create(['fullname' => 'Siti Pengelola', 'username' => 'siti.gudang']);
        $this->pejabat = User::factory()->role(UserRole::PejabatPengadaan)
            ->create(['fullname' => 'Andi Pengadaan', 'username' => 'andi.pengadaan']);

        $this->barang = catatLog($this->pengelola, [
            'activity' => 'Menambah barang: ATK-001 - Kertas HVS',
            'module' => AuditModule::Barang,
            'event' => AuditEvent::Tambah,
            'timestamp' => now()->subDays(10),
        ]);

        $this->usulan = catatLog($this->pejabat, [
            'activity' => 'Menyetujui usulan pengadaan: Usulan #ABCD1234',
            'module' => AuditModule::UsulanPengadaan,
            'event' => AuditEvent::Setuju,
            'timestamp' => now()->subDay(),
        ]);

        $this->admin = User::factory()->role(UserRole::Admin)->create();
    });

    it('menyaring menurut modul', function () {
        expect(idLog(actingAs($this->admin)->get('/log-aktivitas?module='.AuditModule::Barang->value)))
            ->toContain($this->barang->log_id)
            ->not->toContain($this->usulan->log_id);
    });

    it('menyaring menurut aksi', function () {
        expect(idLog(actingAs($this->admin)->get('/log-aktivitas?event='.AuditEvent::Setuju->value)))
            ->toContain($this->usulan->log_id)
            ->not->toContain($this->barang->log_id);
    });

    it('menyaring menurut peran saat aksi', function () {
        expect(idLog(actingAs($this->admin)->get('/log-aktivitas?role='.UserRole::PengelolaGudang->value)))
            ->toContain($this->barang->log_id)
            ->not->toContain($this->usulan->log_id);
    });

    it('menyaring menurut pengguna', function () {
        expect(idLog(actingAs($this->admin)->get('/log-aktivitas?user='.$this->pejabat->user_id)))
            ->toContain($this->usulan->log_id)
            ->not->toContain($this->barang->log_id);
    });

    it('menyaring menurut rentang tanggal', function () {
        expect(idLog(actingAs($this->admin)->get('/log-aktivitas?from='.now()->subDays(3)->toDateString())))
            ->toContain($this->usulan->log_id)
            ->not->toContain($this->barang->log_id);

        expect(idLog(actingAs($this->admin)->get('/log-aktivitas?to='.now()->subDays(3)->toDateString())))
            ->toContain($this->barang->log_id)
            ->not->toContain($this->usulan->log_id);
    });

    it('mencari pada kalimat aktivitas maupun nama pengguna', function () {
        expect(idLog(actingAs($this->admin)->get('/log-aktivitas?q=Kertas')))
            ->toContain($this->barang->log_id)
            ->not->toContain($this->usulan->log_id);

        expect(idLog(actingAs($this->admin)->get('/log-aktivitas?q=Andi')))
            ->toContain($this->usulan->log_id)
            ->not->toContain($this->barang->log_id);
    });

    it('mengurutkan dari yang terbaru', function () {
        expect(idLog(actingAs($this->admin)->get('/log-aktivitas')))
            ->toBe([$this->usulan->log_id, $this->barang->log_id]);
    });

    it('menghitung rekap mengikuti penyaring yang aktif', function () {
        // Masuk dan keluar tidak mengubah data apa pun, jadi tidak ikut
        // dihitung pada kartu Perubahan Data.
        catatLog($this->pengelola, [
            'module' => AuditModule::Autentikasi,
            'event' => AuditEvent::Masuk,
            'activity' => 'Masuk ke sistem',
        ]);

        expect(actingAs($this->admin)->get('/log-aktivitas')->viewData('summary'))
            ->toMatchArray(['total' => 3, 'pengguna' => 2, 'perubahan' => 2]);
    });
});

describe('ekspor CSV', function () {
    beforeEach(function () {
        $this->admin = User::factory()->role(UserRole::Admin)->create();
        $this->pengelola = User::factory()->role(UserRole::PengelolaGudang)
            ->create(['fullname' => 'Siti Pengelola', 'username' => 'siti.gudang']);
    });

    it('mengunduh CSV berisi kolom terstruktur', function () {
        catatLog($this->pengelola, [
            'activity' => 'Menambah barang: ATK-001 - Kertas HVS',
            'ip_address' => '10.10.1.25',
        ]);

        $response = actingAs($this->admin)->get('/log-aktivitas/ekspor/csv');

        $response->assertOk();
        expect($response->headers->get('content-type'))->toContain('text/csv');

        $csv = isiUnduhanLog($response);

        expect($csv)->toStartWith("\xEF\xBB\xBF")          // BOM UTF-8 untuk Excel
            // fputcsv mengutip field yang memuat spasi, jadi judul kolom berkutip.
            ->and($csv)->toContain('Waktu;"Nama Lengkap";"Nama Pengguna";Peran;Modul')
            ->and($csv)->toContain('Siti Pengelola')
            ->and($csv)->toContain('siti.gudang')
            ->and($csv)->toContain('Pengelola Gudang')
            ->and($csv)->toContain('Master Barang')
            ->and($csv)->toContain('Menambah')
            ->and($csv)->toContain('Kertas HVS')
            ->and($csv)->toContain('10.10.1.25');
    });

    it('menghormati penyaring yang sama dengan layar', function () {
        catatLog($this->pengelola, ['activity' => 'Menambah barang: ATK-001 - Kertas HVS']);
        catatLog($this->pengelola, [
            'activity' => 'Menyetujui usulan pengadaan: Usulan #ABCD1234',
            'module' => AuditModule::UsulanPengadaan,
            'event' => AuditEvent::Setuju,
        ]);

        $csv = isiUnduhanLog(actingAs($this->admin)->get('/log-aktivitas/ekspor/csv?module='.AuditModule::Barang->value));

        expect($csv)->toContain('Kertas HVS')
            ->and($csv)->not->toContain('Usulan #ABCD1234');
    });

    it('menyertakan id objek supaya baris dapat dicocokkan balik ke dokumennya', function () {
        $item = Item::factory()->create();
        catatLog($this->pengelola, ['subject_type' => 'barang', 'subject_id' => $item->item_id]);

        expect(isiUnduhanLog(actingAs($this->admin)->get('/log-aktivitas/ekspor/csv')))
            ->toContain($item->item_id);
    });

    it('menetralkan sel yang akan dibaca Excel sebagai rumus', function () {
        // Nama berkas unggahan berasal dari luar dan tidak dipercaya; nama
        // seperti ini akan dijalankan Excel begitu berkasnya dibuka.
        catatLog($this->pengelola, [
            'activity' => '=HYPERLINK("http://jahat.example","klik")',
        ]);

        $csv = isiUnduhanLog(actingAs($this->admin)->get('/log-aktivitas/ekspor/csv'));

        expect($csv)->toContain("'=HYPERLINK")
            ->and($csv)->not->toContain(';=HYPERLINK');
    });

    it('menghasilkan CSV walau tidak ada data pada penyaring', function () {
        $response = actingAs($this->admin)->get('/log-aktivitas/ekspor/csv?q=TidakAdaSamaSekali');

        $response->assertOk();
        expect(isiUnduhanLog($response))->toContain('Waktu;"Nama Lengkap"');
    });
});
