<?php

use App\Enums\AlasanSelisih;
use App\Enums\OpnameStatus;
use App\Enums\PeriodeOpname;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\StockOpname;
use App\Models\User;
use App\Support\OpnamePoster;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lembar laporan dari berkas .xlsx yang diunduh. Nama pembantu unik: berkas
 * Pest berbagi ruang nama global.
 */
function lembarExcelOpname(TestResponse $response): Worksheet
{
    $berkas = tempnam(sys_get_temp_dir(), 'opname');
    file_put_contents($berkas, $response->streamedContent());

    try {
        return IOFactory::load($berkas)->getActiveSheet();
    } finally {
        unlink($berkas);
    }
}

/**
 * Baris pertama — nilainya sudah diformat seperti tampil di Excel — yang
 * kolom ke-`$kolom`-nya (dari 0) berisi teks itu.
 */
function barisExcelOpname(Worksheet $lembar, string $teks, int $kolom = 0): ?array
{
    return collect($lembar->toArray(null, true, true, false))
        ->first(fn (array $baris) => $baris[$kolom] === $teks);
}

function drafExcelOpname(User $petugas, PeriodeOpname $periode = PeriodeOpname::TriwulanIV): StockOpname
{
    return StockOpname::create([
        'opname_date' => now(),
        'user_id' => $petugas->user_id,
        'status' => OpnameStatus::Draf,
        'period_type' => $periode,
        'period_year' => 2026,
    ]);
}

beforeEach(function () {
    $this->petugas = User::factory()->role(UserRole::PengelolaGudang)
        ->create(['fullname' => 'Siti Pengelola']);

    $this->item = Item::factory()->create([
        'item_code' => 'ATK-001', 'item_name' => 'Kertas HVS A4', 'stock_current' => 100,
    ]);
    $this->tidakDiisi = Item::factory()->create([
        'item_code' => 'ATK-002', 'item_name' => 'Pulpen Hitam', 'stock_current' => 40,
    ]);

    $this->opname = drafExcelOpname($this->petugas, PeriodeOpname::TriwulanIII);

    $this->opname->details()->create([
        'item_id' => $this->item->item_id,
        'stock_stored' => 100,
        'stock_real' => 95,
        'difference' => -5,
        'counted_at' => now(),
        'adjustment_reason' => AlasanSelisih::BarangRusak,
        'adjustment_note' => 'Lima rim basah terkena rembesan atap.',
    ]);

    // Dikosongkan petugas: mengikuti stok sistem saat diposting (ADR 0056).
    $this->opname->details()->create([
        'item_id' => $this->tidakDiisi->item_id,
        'stock_stored' => 40,
    ]);

    OpnamePoster::post($this->opname->fresh('details'), $this->petugas);
});

describe('hak akses', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        $this->get("/opname/{$this->opname->opname_id}/excel")->assertRedirect('/login');
    });

    it('ditolak untuk Pejabat Pengadaan', function () {
        $this->actingAs(User::factory()->role(UserRole::PejabatPengadaan)->create())
            ->get("/opname/{$this->opname->opname_id}/excel")
            ->assertForbidden();
    });

    it('mengizinkan Pengelola Gudang, Kasubbag, dan Admin mengunduh', function () {
        $aktor = [
            $this->petugas,
            User::factory()->role(UserRole::KasubbagUmumTi)->create(),
            User::factory()->role(UserRole::Admin)->create(),
        ];

        foreach ($aktor as $user) {
            $this->actingAs($user)->get("/opname/{$this->opname->opname_id}/excel")
                ->assertOk()
                ->assertDownload('laporan-opname-2026-'.PeriodeOpname::TriwulanIII->value.'.xlsx')
                ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
    });

    it('tidak lagi menyediakan laporan PDF', function () {
        $this->actingAs($this->petugas)
            ->get("/opname/{$this->opname->opname_id}/cetak")
            ->assertNotFound();
    });
});

describe('isi laporan', function () {
    it('memuat identitas, rekap, dan rincian beserta alasan selisih', function () {
        $lembar = lembarExcelOpname(
            $this->actingAs($this->petugas)->get("/opname/{$this->opname->opname_id}/excel"),
        );

        expect($lembar->getTitle())->toBe('Stok Opname')
            ->and($lembar->getCell('A1')->getValue())->toBe('Laporan Stok Opname Persediaan')
            ->and(barisExcelOpname($lembar, 'Petugas')[2])->toBe('Siti Pengelola')
            ->and(barisExcelOpname($lembar, 'Status')[2])->toBe('Selesai')
            ->and(barisExcelOpname($lembar, 'Jumlah Barang')[2])->toBe('2')
            ->and(barisExcelOpname($lembar, 'Diisi Petugas')[2])->toBe('1')
            ->and(barisExcelOpname($lembar, 'Barang Berselisih')[2])->toBe('1')
            ->and(barisExcelOpname($lembar, 'Selisih Kurang')[2])->toBe('5');

        $judul = barisExcelOpname($lembar, 'No');
        expect(array_slice($judul, 0, 12))->toBe([
            'No', 'Kode Barang', 'Nama Barang', 'Satuan', 'Stok Sistem', 'Stok Fisik', 'Selisih',
            'Penyesuaian', 'Stok Saat Posting', 'Pencacahan', 'Alasan Selisih', 'Keterangan',
        ]);

        $baris = barisExcelOpname($lembar, 'ATK-001', 1);
        expect($baris[2])->toBe('Kertas HVS A4')
            ->and($baris[4])->toBe('100')
            ->and($baris[5])->toBe('95')
            ->and($baris[6])->toBe('-5')
            ->and($baris[7])->toBe('-5')
            ->and($baris[8])->toBe('100')
            ->and($baris[9])->toBe('Diisi petugas')
            ->and($baris[10])->toBe('Barang Rusak')
            ->and($baris[11])->toBe('Lima rim basah terkena rembesan atap.');

        // Tanpa ruang tanda tangan.
        expect(barisExcelOpname($lembar, 'Mengetahui,', 1))->toBeNull()
            ->and(barisExcelOpname($lembar, 'Pengelola Gudang Persediaan', 9))->toBeNull();
    });

    it('menandai barang yang tidak diisi sebagai mengikuti stok sistem', function () {
        $lembar = lembarExcelOpname(
            $this->actingAs($this->petugas)->get("/opname/{$this->opname->opname_id}/excel"),
        );

        $baris = barisExcelOpname($lembar, 'ATK-002', 1);
        expect($baris[4])->toBe('40')
            ->and($baris[5])->toBe('40')
            ->and($baris[6])->toBe('0')
            ->and($baris[7])->toBe('0')
            ->and($baris[9])->toBe('Mengikuti stok sistem')
            ->and($baris[10])->toBeNull()
            ->and(barisExcelOpname($lembar, 'Mengikuti Stok Sistem')[2])->toBe('1');

        $catatan = collect($lembar->toArray(null, true, true, false))
            ->pluck(0)
            ->filter(fn ($sel) => is_string($sel) && str_contains($sel, '"Mengikuti stok sistem"'));
        expect($catatan)->toHaveCount(1);
    });

    it('mengunduh opname yang masih draf dengan barang belum diisi', function () {
        $draf = drafExcelOpname($this->petugas);
        $draf->details()->create(['item_id' => $this->item->item_id, 'stock_stored' => 95]);

        $lembar = lembarExcelOpname(
            $this->actingAs($this->petugas)->get("/opname/{$draf->opname_id}/excel"),
        );

        $baris = barisExcelOpname($lembar, 'ATK-001', 1);
        expect($baris[5])->toBeNull()
            ->and($baris[9])->toBe('Belum diisi')
            ->and(barisExcelOpname($lembar, 'Belum Diisi')[2])->toBe('1')
            ->and(barisExcelOpname($lembar, 'Mengikuti Stok Sistem'))->toBeNull();
    });

    // Keterangan diketik pengguna; PhpSpreadsheet akan menjadikannya rumus
    // bila tidak ditulis eksplisit sebagai teks.
    it('menulis keterangan berawalan "=" sebagai teks, bukan rumus', function () {
        $draf = drafExcelOpname($this->petugas);
        $draf->details()->create([
            'item_id' => $this->item->item_id,
            'stock_stored' => 95,
            'stock_real' => 90,
            'difference' => -5,
            'counted_at' => now(),
            'adjustment_reason' => AlasanSelisih::Lainnya,
            'adjustment_note' => '=HYPERLINK("http://contoh.test")',
        ]);

        $lembar = lembarExcelOpname(
            $this->actingAs($this->petugas)->get("/opname/{$draf->opname_id}/excel"),
        );

        $sel = collect($lembar->getRowIterator())
            ->map(fn ($row) => $lembar->getCell('L'.$row->getRowIndex()))
            ->first(fn ($cell) => $cell->getValue() === '=HYPERLINK("http://contoh.test")');

        expect($sel)->not->toBeNull()
            ->and($sel->getDataType())->toBe(DataType::TYPE_STRING);
    });
});

describe('halaman hasil', function () {
    it('menampilkan hasil opname beserta alasan selisih di layar', function () {
        $this->actingAs($this->petugas)
            ->get("/opname/{$this->opname->opname_id}")
            ->assertOk()
            ->assertSee('Kertas HVS A4')
            ->assertSee('Barang Rusak')
            ->assertSee('Lima rim basah terkena rembesan atap.')
            ->assertSee('Selesai')
            ->assertSee('Ikut sistem')
            ->assertSee(route('opname.excel', $this->opname), false);
    });

    it('menyaring baris yang diisi petugas dan yang mengikuti stok sistem', function () {
        $this->actingAs($this->petugas)
            ->get("/opname/{$this->opname->opname_id}?baris=diisi")
            ->assertOk()
            ->assertSee(route('items.show', $this->item), false)
            ->assertDontSee(route('items.show', $this->tidakDiisi), false);

        $this->actingAs($this->petugas)
            ->get("/opname/{$this->opname->opname_id}?baris=tidak_diisi")
            ->assertOk()
            ->assertSee(route('items.show', $this->tidakDiisi), false)
            ->assertDontSee(route('items.show', $this->item), false);

        // Penyaring tak dikenal kembali ke semua baris.
        $this->actingAs($this->petugas)
            ->get("/opname/{$this->opname->opname_id}?baris=ngawur")
            ->assertOk()
            ->assertSee(route('items.show', $this->item), false)
            ->assertSee(route('items.show', $this->tidakDiisi), false);
    });
});
