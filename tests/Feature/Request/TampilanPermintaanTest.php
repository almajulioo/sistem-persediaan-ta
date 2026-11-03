<?php

use App\Enums\ItemApplicationStatus;
use App\Enums\ItemApplicationType;
use App\Enums\ModulPengajuan;
use App\Enums\UnitKerja;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\ItemApplication;
use App\Models\Request as ModelsRequest;
use App\Models\RequestDetail;
use App\Models\User;
use App\Support\Warna;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;

/*
 * Tampilan dasbor Pengguna, daftar, dan formulir permintaan/permohonan.
 * Helper bersufiks `Tampilan` supaya tidak bertabrakan dengan berkas lain.
 */

function permintaanTampilan(User $pemohon, string $status, Item $item): ModelsRequest
{
    $request = ModelsRequest::create([
        'user_id' => $pemohon->user_id,
        'date' => now(),
        'type' => 'normal',
        'status' => $status,
        'request_number' => 'REQ-'.Str::upper(Str::random(8)),
    ]);

    RequestDetail::create([
        'request_id' => $request->request_id,
        'item_id' => $item->item_id,
        'quantity_requested' => 2,
    ]);

    return $request;
}

function permohonanTampilan(User $pemohon, ItemApplicationStatus $status, Item $item): ItemApplication
{
    $application = ItemApplication::create([
        'application_number' => 'PMH-'.Str::upper(Str::random(8)),
        'user_id' => $pemohon->user_id,
        'date' => now(),
        'purpose' => 'Kebutuhan rutin',
        'status' => $status,
    ]);
    $application->details()->create(['type' => 'tambah_stok', 'item_id' => $item->item_id, 'quantity' => 3]);

    return $application;
}

beforeEach(function () {
    $this->pemohon = User::factory()->role(UserRole::Pengguna)->create(['unit' => UnitKerja::SubbagianHukum]);
    $this->kertas = Item::factory()->create(['item_name' => 'Kertas A4', 'stock_current' => 10]);
});

describe('dasbor pengguna', function () {
    it('menampilkan dua pintu, permintaan dan permohonan, dengan angka miliknya', function () {
        permintaanTampilan($this->pemohon, 'submitted', $this->kertas);
        permintaanTampilan($this->pemohon, 'delivered', $this->kertas);
        permohonanTampilan($this->pemohon, ItemApplicationStatus::Diajukan, $this->kertas);
        $lain = User::factory()->role(UserRole::Pengguna)->create();
        $milikLain = permintaanTampilan($lain, 'submitted', $this->kertas);

        $response = $this->actingAs($this->pemohon)->get('/dasbor')->assertOk();
        $pintu = $response->viewData('pintuPengguna');

        expect(collect($pintu['permintaan']['statistik'])->pluck('jumlah', 'label')->all())
            ->toBe(['Berjalan' => 1, 'Diserahkan' => 1, 'Selesai' => 0])
            ->and(collect($pintu['permohonan']['statistik'])->pluck('jumlah', 'label')->all())
            ->toBe(['Diajukan' => 1, 'Disetujui' => 0, 'Ditolak' => 0])
            ->and($pintu['permintaan']['perhatian'])->toContain('menunggu konfirmasi')
            ->and($pintu['aktivitas'])->toHaveCount(3);

        $response->assertSee('Buat Permintaan')
            ->assertSee('Ajukan Permohonan')
            ->assertSee('Aktivitas Terbaru')
            ->assertSee('Kertas A4')
            ->assertDontSee($milikLain->request_number);
    });

    it('tidak ditampilkan bagi peran lain', function () {
        $this->actingAs(User::factory()->role(UserRole::PengelolaGudang)->create())
            ->get('/dasbor')
            ->assertViewHas('pintuPengguna', null);
    });
});

describe('dasbor kepala unit', function () {
    beforeEach(function () {
        $this->kepala = User::factory()->role(UserRole::KepalaUnit)->create(['unit' => UnitKerja::SubbagianHukum]);
        $this->mock(\App\Support\RequestDocumentGenerator::class)->shouldIgnoreMissing();
    });

    it('menampilkan antrean persetujuan dan angka khusus unitnya', function () {
        $menunggu = permintaanTampilan($this->pemohon, 'submitted', $this->kertas);
        permintaanTampilan($this->pemohon, 'processed_by_staff', $this->kertas);
        $unitLain = permintaanTampilan(
            User::factory()->role(UserRole::Pengguna)->create(['unit' => UnitKerja::SubbagianSdm]),
            'submitted',
            $this->kertas,
        );

        $response = $this->actingAs($this->kepala)->get('/dasbor')->assertOk();
        $unit = $response->viewData('dasborUnit');

        expect(collect($unit['statistik'])->pluck('jumlah')->all())->toBe([1, 1, 0, 0])
            ->and($unit['antrean']->pluck('request_id')->all())->toBe([$menunggu->request_id])
            ->and($unit['barangTeratas'])->toBe([['nama' => 'Kertas A4', 'total' => 4, 'satuan' => $this->kertas->unit->label(), 'persen' => 100.0]]);

        $response->assertSee('Menunggu Persetujuan Anda')
            ->assertSee('Barang Paling Banyak Diminta')
            ->assertDontSee($unitLain->request_number);
    });

    it('menyetujui dari dasbor lalu kembali ke dasbor', function () {
        $menunggu = permintaanTampilan($this->pemohon, 'submitted', $this->kertas);

        $this->actingAs($this->kepala)
            ->post(route('request.respond', $menunggu), ['action' => 'approve', 'kembali' => 'dasbor'])
            ->assertRedirect(route('dashboard'));

        expect($menunggu->refresh()->status)->toBe('approved_by_head');
    });

    it('tidak ditampilkan bagi Pengguna', function () {
        $this->actingAs($this->pemohon)->get('/dasbor')->assertViewHas('dasborUnit', null);
    });
});

describe('daftar permintaan umum', function () {
    it('memisahkan menunggu, ditolak, dan digabung ke dalam tab', function () {
        $gudang = User::factory()->role(UserRole::PengelolaGudang)->create();
        $umum = fn (string $status) => ModelsRequest::create([
            'user_id' => $this->pemohon->user_id,
            'date' => now(),
            'type' => 'umum',
            'status' => $status,
            'request_number' => 'REQ-UMUM-'.Str::upper(Str::random(8)),
        ]);
        $menunggu = $umum('submitted');
        $ditolak = $umum('rejected');

        $this->actingAs($gudang);

        $response = $this->get(route('request.umum.index'))->assertOk();
        expect(collect($response->viewData('tabs'))->pluck('jumlah')->all())->toBe([1, 1, 0]);
        $response->assertSee($menunggu->request_number)->assertDontSee($ditolak->request_number);

        $this->get(route('request.umum.index', ['view' => 'ditolak']))
            ->assertSee($ditolak->request_number)
            ->assertDontSee($menunggu->request_number);
    });
});

describe('daftar permintaan', function () {
    it('menghitung tab sesuai tahap dan menyaring isinya', function () {
        permintaanTampilan($this->pemohon, 'submitted', $this->kertas);
        permintaanTampilan($this->pemohon, 'approved_by_head', $this->kertas);
        $diserahkan = permintaanTampilan($this->pemohon, 'delivered', $this->kertas);
        permintaanTampilan($this->pemohon, 'rejected', $this->kertas);

        $this->actingAs($this->pemohon);

        $tabs = $this->get(route('request.index'))->assertOk()->viewData('tabs');
        expect(collect($tabs)->pluck('jumlah')->all())->toBe([4, 2, 1, 0, 1]);

        $this->get(route('request.index', ['tahap' => 'diserahkan']))
            ->assertOk()
            ->assertViewHas('requests', fn ($requests) => $requests->pluck('request_id')->all() === [$diserahkan->request_id])
            ->assertSee('Kertas A4');
    });

    it('menampilkan keadaan kosong dengan ajakan membuat permintaan', function () {
        $this->actingAs($this->pemohon)
            ->get(route('request.index'))
            ->assertOk()
            ->assertSee('Belum ada permintaan.');
    });
});

describe('daftar permohonan', function () {
    it('menghitung tab per status dan menyaring isinya', function () {
        permohonanTampilan($this->pemohon, ItemApplicationStatus::Diajukan, $this->kertas);
        $disetujui = permohonanTampilan($this->pemohon, ItemApplicationStatus::Disetujui, $this->kertas);

        $this->actingAs($this->pemohon);

        $tabs = $this->get(route('item-applications.index'))->assertOk()->viewData('tabs');
        expect(collect($tabs)->pluck('jumlah')->all())->toBe([2, 1, 1, 0]);

        $this->get(route('item-applications.index', ['status' => 'disetujui']))
            ->assertOk()
            ->assertViewHas('applications', fn ($applications) => $applications->pluck('item_application_id')->all() === [$disetujui->item_application_id]);
    });
});

describe('detail permohonan', function () {
    it('memakai modal konfirmasi, bukan dialog bawaan browser, untuk menyetujui', function () {
        $application = permohonanTampilan($this->pemohon, ItemApplicationStatus::Diajukan, $this->kertas);

        $this->actingAs(User::factory()->role(UserRole::PengelolaGudang)->create())
            ->get(route('item-applications.show', $application))
            ->assertOk()
            ->assertSee('Teruskan permohonan ini?')
            ->assertSee('1 barang masuk draf usulan pengadaan')
            ->assertSee('Kertas A4')
            ->assertDontSee('confirm(', false);
    });
});

describe('formulir', function () {
    it('merender formulir permintaan dua kolom', function () {
        $this->actingAs($this->pemohon)
            ->get(route('request.create'))
            ->assertOk()
            ->assertSee('Pilih Barang')
            ->assertSee('Daftar Barang')
            ->assertSee('Ajukan Permintaan');
    });

    it('merender formulir ubah kuantitas', function () {
        $request = permintaanTampilan($this->pemohon, 'approved_by_head', $this->kertas);

        $this->actingAs(User::factory()->role(UserRole::PengelolaGudang)->create())
            ->get(route('request.edit', $request))
            ->assertOk()
            ->assertSee('Alasan Perubahan')
            ->assertSee('Simpan Kuantitas');
    });

    it('merender formulir permintaan umum dua kolom', function () {
        $pemohonUmum = User::factory()->role(UserRole::Pengguna)->create(['unit' => UnitKerja::SubbagianUmumTi]);

        $this->actingAs($pemohonUmum)
            ->get(route('request.umum.create'))
            ->assertOk()
            ->assertSee('Pilih Barang')
            ->assertSee('Daftar Barang')
            ->assertSee('Kirim Permintaan Umum');
    });

    it('merender formulir ubah permintaan umum beserta pemohon dan jumlah semula', function () {
        $pemohonUmum = User::factory()->role(UserRole::Pengguna)->create([
            'unit' => UnitKerja::SubbagianUmumTi,
            'fullname' => 'Rina Umum',
        ]);
        $request = ModelsRequest::create([
            'user_id' => $pemohonUmum->user_id,
            'date' => now(),
            'type' => 'umum',
            'status' => 'submitted',
            'request_number' => 'REQ-UMUM-'.Str::upper(Str::random(8)),
        ]);
        RequestDetail::create(['request_id' => $request->request_id, 'item_id' => $this->kertas->item_id, 'quantity_requested' => 4]);

        $response = $this->actingAs(User::factory()->role(UserRole::PengelolaGudang)->create())
            ->get(route('request.umum.edit', $request))
            ->assertOk()
            ->assertSee('Rina Umum')
            ->assertSee('Alasan Perubahan')
            ->assertSee('Simpan Perubahan');

        expect($response->content())->toContain('"'.$this->kertas->item_id.'":4');
    });

    it('merender formulir permohonan dua kolom', function () {
        $this->actingAs($this->pemohon)
            ->get(route('item-applications.create'))
            ->assertOk()
            ->assertSee('Tambah Barang')
            ->assertSee('Daftar Barang')
            ->assertSee('Ajukan Permohonan');
    });
});

describe('kategori warna dan ikon', function () {
    it('memberi setiap status dan jenis warna serta ikon yang dikenal', function () {
        $pasangan = [
            ...array_map(fn (string $status) => [ModelsRequest::statusWarna($status), ModelsRequest::statusIcon($status)], array_keys(ModelsRequest::STATUS_LABELS)),
            ...array_map(fn (string $type) => [(new ModelsRequest(['type' => $type]))->jenisWarna(), (new ModelsRequest(['type' => $type]))->jenisIcon()], ['normal', 'emergency', 'umum']),
            ...array_map(fn (ItemApplicationStatus $status) => [$status->warna(), $status->icon()], ItemApplicationStatus::cases()),
            ...array_map(fn (ItemApplicationType $type) => [$type->warna(), $type->icon()], ItemApplicationType::cases()),
        ];

        foreach ($pasangan as [$warna, $ikon]) {
            expect($warna)->toBeIn(Warna::nama())
                ->and(Blade::render('<x-ui.icon :name="$ikon" />', ['ikon' => $ikon]))->toContain('<svg');
        }
    });

    it('membedakan setiap tahap permintaan dengan warna sendiri', function () {
        $tahap = ['submitted', 'approved_by_head', 'processed_by_staff', 'in_issuance', 'delivered', 'completed', 'rejected'];

        expect(collect($tahap)->map(fn (string $status) => ModelsRequest::statusWarna($status))->unique())->toHaveCount(count($tahap))
            ->and(ItemApplicationType::BarangBaru->warna())->not->toBe(ItemApplicationStatus::Diajukan->warna());
    });

    it('menolak nama warna yang tidak dikenal', function () {
        Warna::lembut('ungu-muda');
    })->throws(InvalidArgumentException::class);

    it('menandai baris daftar permintaan dengan warna status dan menampilkan keterangannya', function () {
        permintaanTampilan($this->pemohon, 'approved_by_head', $this->kertas);

        $this->actingAs($this->pemohon)
            ->get(route('request.index'))
            ->assertOk()
            ->assertSee('Keterangan status:')
            ->assertSee(Warna::garis(ModelsRequest::statusWarna('approved_by_head')))
            ->assertSee('Disetujui Kepala Subbagian');
    });

    it('menghitung kartu ringkasan daftar permohonan dalam cakupan pemohon', function () {
        permohonanTampilan($this->pemohon, ItemApplicationStatus::Diajukan, $this->kertas);
        permohonanTampilan($this->pemohon, ItemApplicationStatus::Disetujui, $this->kertas);
        permohonanTampilan(User::factory()->role(UserRole::Pengguna)->create(), ItemApplicationStatus::Diajukan, $this->kertas);

        $this->actingAs($this->pemohon)
            ->get(route('item-applications.index'))
            ->assertOk()
            ->assertViewHas('summary', ['menunggu' => 1, 'bulan_ini' => 2, 'disetujui_bulan_ini' => 1])
            ->assertSee('1 Tambah Stok');
    });
});

describe('identitas modul permintaan dan permohonan', function () {
    it('memberi kedua modul warna sendiri yang tidak dipakai status mana pun', function () {
        $warnaStatus = collect([
            ...array_map(fn (string $status) => ModelsRequest::statusWarna($status), array_keys(ModelsRequest::STATUS_LABELS)),
            ...array_map(fn (ItemApplicationStatus $status) => $status->warna(), ItemApplicationStatus::cases()),
            ...array_map(fn (ItemApplicationType $type) => $type->warna(), ItemApplicationType::cases()),
        ]);

        foreach (ModulPengajuan::cases() as $modul) {
            expect($modul->warna())->toBeIn(Warna::nama())
                ->and($warnaStatus)->not->toContain($modul->warna())
                ->and($modul->lawan()->lawan())->toBe($modul);
        }

        expect(ModulPengajuan::Permintaan->warna())->not->toBe(ModulPengajuan::Permohonan->warna());
    });

    it('menampilkan catatan perbedaan dan tautan ke modul satunya di daftar dan formulir', function () {
        $this->actingAs($this->pemohon);

        foreach ([route('request.index'), route('request.create')] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('dipakai bila '.lcfirst(ModulPengajuan::Permintaan->dipakaiBila()))
                ->assertSee('Disetujui Kepala Subbagian Anda, lalu barang diserahkan Pengelola Gudang.')
                ->assertSee(ModulPengajuan::Permohonan->ajakan())
                ->assertSee(route('item-applications.create'));
        }

        foreach ([route('item-applications.index'), route('item-applications.create')] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('dipakai bila '.lcfirst(ModulPengajuan::Permohonan->dipakaiBila()))
                ->assertSee(ModulPengajuan::Permintaan->ajakan())
                ->assertSee(route('request.create'));
        }
    });

    it('membandingkan kedua pintu berdampingan di dasbor Pengguna', function () {
        $this->actingAs($this->pemohon)
            ->get('/dasbor')
            ->assertOk()
            ->assertSeeInOrder([
                ModulPengajuan::Permintaan->label(), 'Dipakai bila', ModulPengajuan::Permintaan->dipakaiBila(),
                ModulPengajuan::Permohonan->label(), 'Dipakai bila', ModulPengajuan::Permohonan->dipakaiBila(),
            ])
            ->assertSee(ModulPengajuan::Permohonan->hasil());
    });
});
