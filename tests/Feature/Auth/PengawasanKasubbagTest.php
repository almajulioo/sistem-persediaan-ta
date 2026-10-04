<?php

use App\Enums\AlasanPengadaan;
use App\Enums\OpnameStatus;
use App\Enums\PeriodeOpname;
use App\Enums\ProcurementStatus;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\Procurement;
use App\Models\ProcurementDocument;
use App\Models\StockOpname;
use App\Models\Supplier;
use App\Models\User;
use App\Support\DocumentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Kasubbag Umum dan TI sebagai pengawas baca-saja gudang dan pengadaan
 * (ADR 0041): membaca dan mencetak seluruh data subbagiannya, tidak bisa
 * mengubah apa pun selain memutuskan realisasi. Fixture didefinisikan di
 * berkas ini dan bersufiks `Pengawasan`.
 */

function penggunaPengawasan(UserRole $role): User
{
    return User::factory()->role($role)->create();
}

function opnamePengawasan(User $petugas, OpnameStatus $status): StockOpname
{
    return StockOpname::create([
        'opname_date' => now(),
        'user_id' => $petugas->user_id,
        'status' => $status,
        'period_type' => $status === OpnameStatus::Draf ? PeriodeOpname::TriwulanIII : PeriodeOpname::TriwulanII,
        'period_year' => now()->year,
    ]);
}

function realisasiPengawasan(): Procurement
{
    return Procurement::create([
        'supplier_id' => Supplier::factory()->create()->supplier_id,
        'procurement_date' => now(),
        'basis_reason' => AlasanPengadaan::KebutuhanMendesak,
        'basis_note' => 'Kebutuhan uji pengawasan.',
        'status' => ProcurementStatus::MenungguPersetujuan,
        'user_id' => penggunaPengawasan(UserRole::PejabatPengadaan)->user_id,
    ]);
}

beforeEach(function () {
    Storage::fake(DocumentStorage::DISK);

    $this->kasubbag = penggunaPengawasan(UserRole::KasubbagUmumTi);
    $this->petugas = penggunaPengawasan(UserRole::PengelolaGudang);
    $this->item = Item::factory()->create();
    $this->supplier = Supplier::factory()->create();
    $this->draf = opnamePengawasan($this->petugas, OpnameStatus::Draf);
    $this->selesai = opnamePengawasan($this->petugas, OpnameStatus::Selesai);
    $this->procurement = realisasiPengawasan();
});

describe('gate pengawasan', function () {
    it('membuka baca bagi Kasubbag tanpa membuka tulis', function () {
        expect($this->kasubbag->can('akses-gudang'))->toBeTrue()
            ->and($this->kasubbag->can('akses-penyedia'))->toBeTrue()
            ->and($this->kasubbag->can('akses-pengadaan'))->toBeTrue()
            ->and($this->kasubbag->can('setujui-pengadaan'))->toBeTrue()
            ->and($this->kasubbag->can('unggah-berkas-pengadaan'))->toBeFalse()
            ->and($this->kasubbag->can('kelola-gudang'))->toBeFalse()
            ->and($this->kasubbag->can('kelola-pengadaan'))->toBeFalse()
            ->and($this->kasubbag->can('kelola-permintaan'))->toBeFalse()
            ->and($this->kasubbag->can('kelola-pengguna'))->toBeFalse();
    });

    it('tidak melebarkan akses pelaksana ke wilayah satu sama lain', function () {
        $pejabat = penggunaPengawasan(UserRole::PejabatPengadaan);

        expect($pejabat->can('akses-penyedia'))->toBeTrue()
            ->and($pejabat->can('unggah-berkas-pengadaan'))->toBeTrue()
            ->and($pejabat->can('akses-gudang'))->toBeFalse()
            ->and($this->petugas->can('akses-gudang'))->toBeTrue()
            ->and($this->petugas->can('unggah-berkas-pengadaan'))->toBeTrue()
            ->and($this->petugas->can('akses-penyedia'))->toBeFalse();

        $admin = penggunaPengawasan(UserRole::Admin);

        expect($admin->can('akses-gudang'))->toBeTrue()
            ->and($admin->can('akses-penyedia'))->toBeTrue()
            ->and($admin->can('unggah-berkas-pengadaan'))->toBeTrue();
    });
});

describe('Kasubbag membaca', function () {
    it('membuka seluruh halaman baca dan cetak gudang, penyedia, dan pengadaan', function () {
        $halaman = [
            '/barang',
            "/barang/{$this->item->item_id}",
            "/barang/{$this->item->item_id}/kartu-persediaan",
            '/mutasi',
            '/mutasi/cetak/csv',
            '/mutasi/cetak/pdf',
            '/opname',
            "/opname/{$this->draf->opname_id}",
            "/opname/{$this->selesai->opname_id}",
            "/opname/{$this->selesai->opname_id}/cetak",
            '/penyedia',
            "/penyedia/{$this->supplier->supplier_id}",
            '/usulan-pengadaan',
            '/usulan-barang',
            '/pengadaan',
            "/pengadaan/{$this->procurement->procurement_id}",
        ];

        foreach ($halaman as $url) {
            $this->actingAs($this->kasubbag)->get($url)->assertOk();
        }
    });

    it('tetap menutup halaman baca gudang bagi Pejabat Pengadaan', function () {
        $pejabat = penggunaPengawasan(UserRole::PejabatPengadaan);

        $this->actingAs($pejabat)->get('/barang')->assertForbidden();
        $this->actingAs($pejabat)->get('/mutasi')->assertForbidden();
        $this->actingAs($this->petugas)->get('/penyedia')->assertForbidden();
    });
});

describe('Kasubbag tidak bisa mengubah', function () {
    it('menolak setiap halaman formulir', function () {
        $halaman = [
            '/barang/create',
            "/barang/{$this->item->item_id}/edit",
            '/mutasi/create',
            '/opname/create',
            "/opname/{$this->draf->opname_id}/edit",
            '/penyedia/create',
            "/penyedia/{$this->supplier->supplier_id}/edit",
            '/usulan-pengadaan/create',
            '/usulan-pengadaan/draf',
            '/usulan-barang/create',
            '/pengadaan/create',
            "/pengadaan/{$this->procurement->procurement_id}/edit",
            "/pengadaan/{$this->procurement->procurement_id}/penerimaan/create",
            '/pengguna',
            '/log-aktivitas',
        ];

        foreach ($halaman as $url) {
            $this->actingAs($this->kasubbag)->get($url)->assertForbidden();
        }
    });

    it('menolak setiap aksi yang mengubah data', function () {
        $item = $this->item->item_id;
        $supplier = $this->supplier->supplier_id;
        $draf = $this->draf->opname_id;
        $procurement = $this->procurement->procurement_id;

        $aksi = [
            ['post', '/barang'],
            ['put', "/barang/{$item}"],
            ['delete', "/barang/{$item}"],
            ['patch', "/barang/{$item}/status"],
            ['post', '/mutasi'],
            ['post', '/opname'],
            ['put', "/opname/{$draf}"],
            ['delete', "/opname/{$draf}"],
            ['post', "/opname/{$draf}/posting"],
            ['post', '/penyedia'],
            ['put', "/penyedia/{$supplier}"],
            ['delete', "/penyedia/{$supplier}"],
            ['patch', "/penyedia/{$supplier}/status"],
            ['post', '/usulan-pengadaan'],
            ['post', '/pengadaan'],
            ['put', "/pengadaan/{$procurement}"],
            ['patch', "/pengadaan/{$procurement}/batal"],
            ['patch', "/pengadaan/{$procurement}/dibelanjakan"],
        ];

        foreach ($aksi as [$metode, $url]) {
            $this->actingAs($this->kasubbag)->{$metode}($url)->assertForbidden();
        }

        expect(Item::find($item))->not->toBeNull()
            ->and(Supplier::find($supplier))->not->toBeNull()
            ->and(StockOpname::find($draf))->not->toBeNull()
            ->and($this->procurement->fresh()->status)->toBe(ProcurementStatus::MenungguPersetujuan);
    });

    it('menolak Kasubbag mengunggah berkas ke realisasi yang ia nilai', function () {
        $this->actingAs($this->kasubbag)
            ->post("/pengadaan/{$this->procurement->procurement_id}/dokumen", [
                'type' => 'bast',
                'file' => UploadedFile::fake()->create('bast.pdf', 10, 'application/pdf'),
            ])
            ->assertForbidden();

        expect(ProcurementDocument::count())->toBe(0)
            ->and(Storage::disk(DocumentStorage::DISK)->allFiles())->toBe([]);
    });

    it('tetap mengizinkan pelaksana mengunggah berkas', function () {
        $this->actingAs($this->petugas)
            ->post("/pengadaan/{$this->procurement->procurement_id}/dokumen", [
                'type' => 'spk_kontrak',
                'file' => UploadedFile::fake()->create('spk.pdf', 10, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        expect(ProcurementDocument::count())->toBe(1);
    });
});

describe('tampilan baca-saja', function () {
    it('menyembunyikan tombol ubah dari Kasubbag tetapi menyisakan cetak', function () {
        $this->actingAs($this->kasubbag);

        $this->get('/barang')
            ->assertSee(route('items.show', $this->item), false)
            ->assertDontSee(route('items.create'), false)
            ->assertDontSee(route('items.edit', $this->item), false)
            ->assertDontSee(route('items.toggle-status', $this->item), false)
            ->assertDontSee('Aksi');

        $this->get("/barang/{$this->item->item_id}")
            ->assertSee(route('items.kartu-persediaan', $this->item), false)
            ->assertDontSee(route('items.edit', $this->item), false);

        $this->get('/mutasi')
            ->assertSee(route('mutations.pdf'), false)
            ->assertDontSee(route('mutations.create'), false);

        $this->get('/opname')
            ->assertSee(route('opname.show', $this->draf), false)
            ->assertSee(route('opname.pdf', $this->selesai), false)
            ->assertDontSee(route('opname.create'), false)
            ->assertDontSee(route('opname.edit', $this->draf), false);

        $this->get("/opname/{$this->draf->opname_id}")
            ->assertDontSee(route('opname.edit', $this->draf), false);

        $this->get('/penyedia')
            ->assertSee(route('suppliers.show', $this->supplier), false)
            ->assertDontSee(route('suppliers.create'), false)
            ->assertDontSee(route('suppliers.edit', $this->supplier), false)
            ->assertDontSee(route('suppliers.toggle-status', $this->supplier), false);

        $this->get("/penyedia/{$this->supplier->supplier_id}")
            ->assertDontSee(route('suppliers.edit', $this->supplier), false);

        $this->get("/pengadaan/{$this->procurement->procurement_id}")
            ->assertSee(route('procurements.approve', $this->procurement), false)
            ->assertDontSee('action="'.route('procurement-documents.store', $this->procurement).'"', false);
    });

    it('tetap menampilkan tombol ubah bagi pelaksana', function () {
        $this->actingAs($this->petugas);

        $this->get('/barang')
            ->assertSee(route('items.create'), false)
            ->assertSee(route('items.edit', $this->item), false);
        $this->get('/mutasi')->assertSee(route('mutations.create'), false);
        $this->get('/opname')
            ->assertSee(route('opname.create'), false)
            ->assertSee(route('opname.edit', $this->draf), false);
        $this->get("/pengadaan/{$this->procurement->procurement_id}")
            ->assertSee('action="'.route('procurement-documents.store', $this->procurement).'"', false);

        $this->actingAs(penggunaPengawasan(UserRole::PejabatPengadaan))
            ->get('/penyedia')
            ->assertSee(route('suppliers.create'), false)
            ->assertSee(route('suppliers.edit', $this->supplier), false);
    });

    it('menautkan kondisi stok di dasbor Kasubbag', function () {
        $this->actingAs($this->kasubbag)->get('/dasbor')
            ->assertViewHas('stok', fn (array $stok) => $stok['bertaut'] === true);
    });
});

describe('menu samping', function () {
    it('menampilkan gudang dan pengadaan bagi Kasubbag tanpa administrasi sistem', function () {
        // Dicocokkan dengan `href` relatif khas sidebar; ubin dasbor memakai
        // route() yang absolut.
        $this->actingAs($this->kasubbag)->get('/dasbor')
            ->assertSee('href="/barang"', false)
            ->assertSee('href="/mutasi"', false)
            ->assertSee('href="/opname"', false)
            ->assertSee('href="/penyedia"', false)
            ->assertSee('href="/pengadaan?status=menunggu_persetujuan"', false)
            ->assertDontSee('href="/pengguna"', false)
            ->assertDontSee('href="/log-aktivitas"', false);
    });

    it('tidak menampilkan tautan pengadaan dua kali', function (UserRole $role) {
        $html = $this->actingAs(penggunaPengawasan($role))->get('/dasbor')->getContent();

        foreach (['href="/usulan-pengadaan"', 'href="/usulan-barang"', 'href="/pengadaan"'] as $tautan) {
            expect(substr_count($html, $tautan))->toBe(1);
        }
    })->with([
        'Admin' => [UserRole::Admin],
        'Kasubbag' => [UserRole::KasubbagUmumTi],
        'Pengelola Gudang' => [UserRole::PengelolaGudang],
    ]);
});

describe('urutan rute', function () {
    it('tidak membiarkan rute baca berparameter menelan halaman formulir', function () {
        $this->actingAs($this->petugas)->get('/barang/create')->assertOk();
        $this->actingAs($this->petugas)->get('/opname/create')->assertOk();
        $this->actingAs(penggunaPengawasan(UserRole::PejabatPengadaan))->get('/penyedia/create')->assertOk();
    });
});
