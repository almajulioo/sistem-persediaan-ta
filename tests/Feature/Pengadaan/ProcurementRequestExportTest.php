<?php

use App\Enums\ProcurementRequestSource;
use App\Enums\ProcurementRequestStatus;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\ProcurementRequest;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Berkas .xlsx yang diunduh. Nama pembantu unik: berkas Pest berbagi ruang
 * nama global.
 */
function bukuExcelUsulan(TestResponse $response): Spreadsheet
{
    $berkas = tempnam(sys_get_temp_dir(), 'usulan');
    file_put_contents($berkas, $response->streamedContent());

    try {
        return IOFactory::load($berkas);
    } finally {
        unlink($berkas);
    }
}

/**
 * Lembar yang terbuka saat berkas dibuka, atau lembar bernama `$nama`.
 */
function lembarExcelUsulan(TestResponse $response, ?string $nama = null): Worksheet
{
    $buku = bukuExcelUsulan($response);

    return $nama === null ? $buku->getActiveSheet() : $buku->getSheetByNameOrThrow($nama);
}

/**
 * Baris pertama — nilainya sudah diformat seperti tampil di Excel — yang
 * kolom ke-`$kolom`-nya (dari 0) berisi teks itu.
 */
function barisExcelUsulan(Worksheet $lembar, string $teks, int $kolom = 0): ?array
{
    return collect($lembar->toArray(null, true, true, false))
        ->first(fn (array $baris) => $baris[$kolom] === $teks);
}

beforeEach(function () {
    $this->petugas = pengelolaGudang(['fullname' => 'Rina Gudang']);
    $this->pejabat = pejabatPengadaan();
    $this->item = Item::factory()->create(['item_code' => 'ATK-901', 'item_name' => 'Map Plastik']);
});

describe('hak akses', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        $usulan = buatUsulan($this->petugas, [['item_id' => $this->item->item_id, 'amount_request' => 5]]);

        // buatUsulan() masuk sebagai Pengelola Gudang.
        auth()->logout();

        $this->get('/usulan-pengadaan/laporan/excel')->assertRedirect('/login');
        $this->get("/usulan-pengadaan/{$usulan->procurement_request_id}/excel")->assertRedirect('/login');
    });

    it('menolak peran di luar pengadaan', function () {
        $usulan = buatUsulan($this->petugas, [['item_id' => $this->item->item_id, 'amount_request' => 5]]);
        $kepalaUnit = User::factory()->role(UserRole::KepalaUnit)->create();

        $this->actingAs($kepalaUnit)->get('/usulan-pengadaan/laporan/excel')->assertForbidden();
        $this->actingAs($kepalaUnit)->get("/usulan-pengadaan/{$usulan->procurement_request_id}/excel")->assertForbidden();
    });

    it('mengizinkan kedua peran pengadaan, Kasubbag, dan Admin mengunduh', function () {
        $usulan = buatUsulan($this->petugas, [['item_id' => $this->item->item_id, 'amount_request' => 5]]);

        foreach ([$this->petugas, $this->pejabat, kasubbagUmumTi(), User::factory()->role(UserRole::Admin)->create()] as $aktor) {
            $this->actingAs($aktor)->get('/usulan-pengadaan/laporan/excel')
                ->assertOk()
                ->assertDownload()
                ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

            $this->actingAs($aktor)->get("/usulan-pengadaan/{$usulan->procurement_request_id}/excel")
                ->assertOk()
                ->assertDownload('usulan-pengadaan-'.strtoupper(substr($usulan->procurement_request_id, 0, 8)).'.xlsx');
        }
    });
});

describe('ekspor daftar usulan', function () {
    it('hanya memuat usulan yang diajukan dalam rentang tanggal', function () {
        $this->travelTo('2026-01-15 09:00');
        buatUsulan(pengelolaGudang(['fullname' => 'Pengaju Januari']), [['item_id' => $this->item->item_id, 'amount_request' => 5]]);
        $this->travelTo('2026-02-10 10:30');
        $februari = buatUsulan(pengelolaGudang(['fullname' => 'Pengaju Februari']), [['item_id' => $this->item->item_id, 'amount_request' => 5]]);
        $this->travelTo('2026-03-05 08:00');
        buatUsulan(pengelolaGudang(['fullname' => 'Pengaju Maret']), [['item_id' => $this->item->item_id, 'amount_request' => 5]]);
        $this->travelBack();

        $lembar = lembarExcelUsulan($this->actingAs($this->pejabat)
            ->get('/usulan-pengadaan/laporan/excel?from=2026-02-01&to=2026-02-28'));

        expect(barisExcelUsulan($lembar, '01 Februari 2026 s.d. 28 Februari 2026'))->not->toBeNull()
            ->and(barisExcelUsulan($lembar, 'Total Usulan')[2])->toEqual(1)
            ->and(barisExcelUsulan($lembar, 'Pengaju Februari', 3))
            ->toEqual([1, $februari->judul(), '10/02/2026 10:30', 'Pengaju Februari', 1, 'Diajukan'])
            ->and(barisExcelUsulan($lembar, 'Pengaju Januari', 3))->toBeNull()
            ->and(barisExcelUsulan($lembar, 'Pengaju Maret', 3))->toBeNull();
    });

    it('mengikuti penyaring status dan tidak pernah memuat draf', function () {
        buatUsulan(pengelolaGudang(['fullname' => 'Pengaju Diajukan']), [['item_id' => $this->item->item_id, 'amount_request' => 5]]);
        $ditolak = buatUsulan(pengelolaGudang(['fullname' => 'Pengaju Ditolak']), [['item_id' => $this->item->item_id, 'amount_request' => 5]]);
        $this->actingAs($this->pejabat)->patch("/usulan-pengadaan/{$ditolak->procurement_request_id}/tolak");
        ProcurementRequest::create(['status' => ProcurementRequestStatus::Draf]);

        $semua = lembarExcelUsulan($this->actingAs($this->pejabat)->get('/usulan-pengadaan/laporan/excel'));

        expect(barisExcelUsulan($semua, 'Seluruh periode'))->not->toBeNull()
            ->and(barisExcelUsulan($semua, 'Total Usulan')[2])->toEqual(2)
            ->and(barisExcelUsulan($semua, 'Menunggu Persetujuan')[2])->toEqual(1)
            ->and(barisExcelUsulan($semua, 'Ditolak')[2])->toEqual(1);

        $tersaring = lembarExcelUsulan($this->actingAs($this->pejabat)->get('/usulan-pengadaan/laporan/excel?status=ditolak'));

        expect(barisExcelUsulan($tersaring, 'Status: Ditolak'))->not->toBeNull()
            ->and(barisExcelUsulan($tersaring, 'Pengaju Ditolak', 3))->not->toBeNull()
            ->and(barisExcelUsulan($tersaring, 'Pengaju Diajukan', 3))->toBeNull();
    });

    it('menulis tanggal sebagai tanggal Excel dan mengurutkan dari yang terlama', function () {
        $this->travelTo('2026-05-02 14:00');
        buatUsulan(pengelolaGudang(['fullname' => 'Pengaju Baru']), [['item_id' => $this->item->item_id, 'amount_request' => 5]]);
        $this->travelTo('2026-05-01 09:15');
        buatUsulan(pengelolaGudang(['fullname' => 'Pengaju Lama']), [['item_id' => $this->item->item_id, 'amount_request' => 5]]);
        $this->travelBack();

        $lembar = lembarExcelUsulan($this->actingAs($this->pejabat)->get('/usulan-pengadaan/laporan/excel'));
        $baris = collect($lembar->toArray(null, true, true, true));
        $nomor = $baris->search(fn (array $isi) => $isi['D'] === 'Pengaju Lama');

        expect($baris[$nomor]['A'])->toEqual(1)
            ->and($baris[$nomor]['C'])->toBe('01/05/2026 09:15')
            ->and($lembar->getCell("C{$nomor}")->getDataType())->toBe(DataType::TYPE_NUMERIC)
            ->and($baris[$nomor + 1]['D'])->toBe('Pengaju Baru');
    });

    it('memuat dua lembar dan terbuka di lembar Ringkasan', function () {
        buatUsulan($this->petugas, [['item_id' => $this->item->item_id, 'amount_request' => 5]]);

        $buku = bukuExcelUsulan($this->actingAs($this->pejabat)->get('/usulan-pengadaan/laporan/excel'));

        expect($buku->getSheetNames())->toBe(['Ringkasan', 'Rekap Barang'])
            ->and($buku->getActiveSheet()->getTitle())->toBe('Ringkasan');
    });

    it('merekap barang dari semua usulan dalam rentang tanggal', function () {
        $pulpen = Item::factory()->create(['item_code' => 'ATK-903', 'item_name' => 'Pulpen Biru']);
        $lakban = Item::factory()->create(['item_code' => 'ATK-904', 'item_name' => 'Lakban Bening']);

        $this->travelTo('2026-02-03 09:00');
        $disetujui = buatUsulan($this->petugas, [
            ['item_id' => $this->item->item_id, 'amount_request' => 10],
            ['item_id' => $pulpen->item_id, 'amount_request' => 4],
        ]);
        $this->travelTo('2026-02-17 11:00');
        buatUsulan($this->petugas, [['item_id' => $this->item->item_id, 'amount_request' => 6]]);
        // Di luar rentang: tidak boleh ikut terhitung.
        $this->travelTo('2026-03-02 08:00');
        buatUsulan($this->petugas, [
            ['item_id' => $this->item->item_id, 'amount_request' => 50],
            ['item_id' => $lakban->item_id, 'amount_request' => 2],
        ]);
        $this->travelBack();

        $this->actingAs($this->pejabat)->patch("/usulan-pengadaan/{$disetujui->procurement_request_id}/setujui", [
            'granted' => $disetujui->details->mapWithKeys(fn ($detail) => [
                $detail->procurement_request_detail_id => $detail->item_id === $this->item->item_id ? 7 : 0,
            ])->all(),
        ]);

        $lembar = lembarExcelUsulan($this->actingAs($this->pejabat)
            ->get('/usulan-pengadaan/laporan/excel?from=2026-02-01&to=2026-02-28'), 'Rekap Barang');

        expect(barisExcelUsulan($lembar, '01 Februari 2026 s.d. 28 Februari 2026'))->not->toBeNull()
            // Diminta 10 + 6; disetujui 7 dari usulan pertama; 6 milik usulan
            // kedua belum diputuskan. Paling sering diusulkan di atas.
            ->and(barisExcelUsulan($lembar, 'ATK-901', 1))
            ->toEqual([1, 'ATK-901', 'Map Plastik', $this->item->unit->label(), 2, 16, 7, 6])
            ->and(barisExcelUsulan($lembar, 'ATK-903', 1))
            ->toEqual([2, 'ATK-903', 'Pulpen Biru', $pulpen->unit->label(), 1, 4, 0, 0])
            ->and(barisExcelUsulan($lembar, 'ATK-904', 1))->toBeNull();
    });

    it('mengabaikan tanggal penyaring yang tidak sah', function () {
        buatUsulan($this->petugas, [['item_id' => $this->item->item_id, 'amount_request' => 5]]);

        $lembar = lembarExcelUsulan($this->actingAs($this->pejabat)
            ->get('/usulan-pengadaan/laporan/excel?from=bukan-tanggal&to=2026-02-31'));

        expect(barisExcelUsulan($lembar, 'Seluruh periode'))->not->toBeNull()
            ->and(barisExcelUsulan($lembar, 'Total Usulan')[2])->toEqual(1);
    });

    it('menyaring daftar di layar menurut tanggal dan membawa penyaringnya ke tautan ekspor', function () {
        $this->travelTo('2026-01-15 09:00');
        buatUsulan(pengelolaGudang(['fullname' => 'Pengaju Januari']), [['item_id' => $this->item->item_id, 'amount_request' => 5]]);
        $this->travelTo('2026-03-05 08:00');
        buatUsulan(pengelolaGudang(['fullname' => 'Pengaju Maret']), [['item_id' => $this->item->item_id, 'amount_request' => 5]]);
        $this->travelBack();

        $this->actingAs($this->pejabat)
            ->get('/usulan-pengadaan?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->assertSee('Pengaju Maret')
            ->assertDontSee('Pengaju Januari')
            ->assertSee(route('procurement-requests.excel', ['from' => '2026-03-01', 'to' => '2026-03-31']));
    });
});

describe('ekspor satu usulan', function () {
    it('memuat identitas dan rincian barang seperti di layar', function () {
        $lain = Item::factory()->create(['item_code' => 'ATK-902', 'item_name' => 'Pulpen Hitam']);

        $this->travelTo('2026-04-20 13:45');
        $usulan = buatUsulan($this->petugas, [
            ['item_id' => $this->item->item_id, 'amount_request' => 12, 'note' => 'Untuk arsip'],
            ['item_id' => $lain->item_id, 'amount_request' => 3],
        ]);
        $this->travelBack();

        $this->actingAs($this->pejabat)
            ->get("/usulan-pengadaan/{$usulan->procurement_request_id}")
            ->assertSee(route('procurement-requests.excel-detail', $usulan));

        $lembar = lembarExcelUsulan($this->actingAs($this->pejabat)
            ->get("/usulan-pengadaan/{$usulan->procurement_request_id}/excel"));

        expect(barisExcelUsulan($lembar, 'Nomor Usulan')[2])->toBe($usulan->judul())
            ->and(barisExcelUsulan($lembar, 'Status')[2])->toBe('Diajukan')
            ->and(barisExcelUsulan($lembar, 'Pengaju')[2])->toBe('Rina Gudang')
            ->and(barisExcelUsulan($lembar, 'Diajukan')[2])->toBe('20/04/2026 13:45')
            ->and(barisExcelUsulan($lembar, 'Jumlah Barang')[2])->toEqual(2)
            ->and(barisExcelUsulan($lembar, 'ATK-901', 1))->toEqual([
                1, 'ATK-901', 'Map Plastik', ProcurementRequestSource::Manual->label(), null,
                'Untuk arsip', 12, 'Belum diputuskan', $this->item->unit->label(),
            ])
            ->and(barisExcelUsulan($lembar, 'ATK-902', 1)[0])->toEqual(2);
    });

    it('menulis jumlah disetujui setelah usulan diputuskan', function () {
        $usulan = buatUsulan($this->petugas, [['item_id' => $this->item->item_id, 'amount_request' => 12]]);

        $this->actingAs($this->pejabat)->patch("/usulan-pengadaan/{$usulan->procurement_request_id}/setujui", [
            'granted' => [$usulan->details->first()->procurement_request_detail_id => 8],
        ]);

        $lembar = lembarExcelUsulan($this->actingAs($this->pejabat)
            ->get("/usulan-pengadaan/{$usulan->procurement_request_id}/excel"));

        expect(barisExcelUsulan($lembar, 'Status')[2])->toBe(ProcurementRequestStatus::DisetujuiMenungguRealisasi->label())
            ->and(barisExcelUsulan($lembar, 'ATK-901', 1)[6])->toEqual(12)
            ->and(barisExcelUsulan($lembar, 'ATK-901', 1)[7])->toEqual(8);
    });

    it('menulis teks ketikan pengguna apa adanya, bukan sebagai rumus atau angka', function () {
        $item = Item::factory()->create(['item_code' => '0012', 'item_name' => 'Barang Berkode Angka']);
        $usulan = buatUsulan($this->petugas, [['item_id' => $item->item_id, 'amount_request' => 1, 'note' => '=1+1']]);

        $lembar = lembarExcelUsulan($this->actingAs($this->pejabat)
            ->get("/usulan-pengadaan/{$usulan->procurement_request_id}/excel"));
        $baris = barisExcelUsulan($lembar, 'Barang Berkode Angka', 2);

        expect($baris[1])->toBe('0012')
            ->and($baris[5])->toBe('=1+1');
    });

    it('tidak mengekspor draf', function () {
        $draf = ProcurementRequest::create(['status' => ProcurementRequestStatus::Draf]);

        $this->actingAs($this->petugas)
            ->get("/usulan-pengadaan/{$draf->procurement_request_id}/excel")
            ->assertNotFound();
    });
});
