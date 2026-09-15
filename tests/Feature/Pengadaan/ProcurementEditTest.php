<?php

use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Procurement;
use App\Models\Supplier;
use App\Models\User;

// Nama pembantu di berkas Pest bersifat global: `realisasiMenunggu`,
// `realisasiDiterima`, `pembatalanPayload`, `buatUsulan`, `setujuiUsulan`, dan
// `buatRealisasi` dipakai ulang dari berkas uji Tahap 3, 4, dan pembatalan.

/**
 * Muatan pengubahan yang sah: seluruh baris dikirim, dikunci
 * `procurement_detail_id`.
 *
 * @return array<string, mixed>
 */
function ubahPayload(Procurement $procurement, array $overrides = []): array
{
    $items = $procurement->details->values()
        ->map(fn ($detail) => [
            'procurement_detail_id' => $detail->procurement_detail_id,
            'amount' => $detail->amount,
            'unit_price' => $detail->unit_price,
        ])->all();

    return array_merge([
        'supplier_id' => $procurement->supplier_id,
        'procurement_date' => $procurement->procurement_date->toDateString(),
        'items' => $items,
    ], $overrides);
}

beforeEach(function () {
    $this->petugas = pengelolaGudang();
    $this->pejabat = pejabatPengadaan();
    $this->procurement = realisasiMenunggu($this->pejabat, $this->petugas);
    $this->url = "/pengadaan/{$this->procurement->procurement_id}";
});

describe('hak akses ubah realisasi', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        auth()->logout();

        $this->get("{$this->url}/edit")->assertRedirect('/login');
        $this->put($this->url, ubahPayload($this->procurement))->assertRedirect('/login');
    });

    it('menolak Pengelola Gudang', function () {
        // Mengubah realisasi adalah wewenang yang merealisasi, bukan yang
        // menerima barangnya (ADR 0016).
        $this->actingAs($this->petugas)->get("{$this->url}/edit")->assertForbidden();
        $this->actingAs($this->petugas)->put($this->url, ubahPayload($this->procurement))->assertForbidden();
    });

    it('menolak peran di luar pengadaan', function () {
        $this->actingAs(User::factory()->role(UserRole::KepalaUnit)->create())
            ->get("{$this->url}/edit")
            ->assertForbidden();
    });

    it('mengizinkan Pejabat Pengadaan membuka formulir', function () {
        $this->actingAs($this->pejabat)
            ->get("{$this->url}/edit")
            ->assertOk()
            ->assertSee('Ubah '.$this->procurement->judul())
            ->assertSee('tidak dapat dipindah ke usulan lain');
    });

    it('mengizinkan Admin', function () {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get("{$this->url}/edit")
            ->assertOk();
    });
});

describe('mengubah realisasi', function () {
    it('memperbarui penyedia, tanggal, jumlah, dan harga', function () {
        $penyediaBaru = Supplier::factory()->create(['supplier_name' => 'PT Sinar Abadi']);
        $detail = $this->procurement->details->first();

        $this->actingAs($this->pejabat)->put($this->url, ubahPayload($this->procurement, [
            'supplier_id' => $penyediaBaru->supplier_id,
            'procurement_date' => now()->subDay()->toDateString(),
            'items' => [[
                'procurement_detail_id' => $detail->procurement_detail_id,
                'amount' => 7,
                'unit_price' => 45000,
            ]],
        ]))->assertRedirect($this->url);

        $procurement = $this->procurement->fresh('details');
        $baris = $procurement->details->first();

        expect($procurement->supplier_id)->toBe($penyediaBaru->supplier_id)
            ->and($procurement->procurement_date->toDateString())->toBe(now()->subDay()->toDateString())
            ->and($baris->amount)->toBe(7)
            ->and($baris->unit_price)->toBe(45000)
            // Barisnya diperbarui di tempat, bukan dibuat ulang.
            ->and($baris->procurement_detail_id)->toBe($detail->procurement_detail_id);
    });

    it('tidak menyentuh stok sama sekali', function () {
        // Pengubahan hanya mungkin sebelum penerimaan, jadi tidak ada mutasi
        // yang perlu disesuaikan — alasan yang sama yang membuat pembatalan
        // aman pada ADR 0024.
        $item = Item::findOrFail($this->procurement->details->first()->item_id);
        $stokSebelum = $item->stock_current;

        $this->actingAs($this->pejabat)->put($this->url, ubahPayload($this->procurement, [
            'items' => [[
                'procurement_detail_id' => $this->procurement->details->first()->procurement_detail_id,
                'amount' => 3,
                'unit_price' => 1000,
            ]],
        ]));

        expect($item->fresh()->stock_current)->toBe($stokSebelum)
            ->and($item->fresh()->stockMutations()->count())->toBe(0);
    });

    it('mencatat jejak audit', function () {
        $this->actingAs($this->pejabat)->put($this->url, ubahPayload($this->procurement));

        expect(AuditLog::where('activity', 'like', 'Mengubah realisasi pengadaan%')->exists())->toBeTrue();
    });

    it('mengizinkan jumlah melebihi jumlah disetujui usulan', function () {
        // ADR 0029: batas atas jumlah disetujui dicabut, baik saat dibuat
        // maupun saat diubah.
        $this->actingAs($this->pejabat)
            ->put($this->url, ubahPayload($this->procurement, [
                'items' => [[
                    'procurement_detail_id' => $this->procurement->details->first()->procurement_detail_id,
                    'amount' => 11,
                    'unit_price' => 10000,
                ]],
            ]))
            ->assertRedirect($this->url);

        expect($this->procurement->fresh()->details->first()->amount)->toBe(11);
    });

    it('mengizinkan menambah barang di luar usulan', function () {
        $tambahan = Item::factory()->create();

        $this->actingAs($this->pejabat)
            ->put($this->url, ubahPayload($this->procurement, [
                'items' => [
                    [
                        'procurement_detail_id' => $this->procurement->details->first()->procurement_detail_id,
                        'amount' => 10,
                        'unit_price' => 10000,
                    ],
                    [
                        'item_id' => $tambahan->item_id,
                        'amount' => 4,
                        'unit_price' => 8000,
                    ],
                ],
            ]))
            ->assertRedirect($this->url);

        $procurement = $this->procurement->fresh('details');

        expect($procurement->details)->toHaveCount(2)
            ->and($procurement->details->firstWhere('item_id', $tambahan->item_id)->amount)->toBe(4);
    });

    it('mengizinkan menghapus kembali barang tambahan yang bukan dari usulan', function () {
        $tambahan = Item::factory()->create();
        $detailUsulan = $this->procurement->details->first();

        $this->actingAs($this->pejabat)->put($this->url, ubahPayload($this->procurement, [
            'items' => [
                [
                    'procurement_detail_id' => $detailUsulan->procurement_detail_id,
                    'amount' => 10,
                    'unit_price' => 10000,
                ],
                [
                    'item_id' => $tambahan->item_id,
                    'amount' => 4,
                    'unit_price' => 8000,
                ],
            ],
        ]));

        expect($this->procurement->fresh('details')->details)->toHaveCount(2);

        // Baris tambahan dihapus dengan cara tidak menyertakannya lagi —
        // baris usulan tetap wajib dikirim.
        $this->actingAs($this->pejabat)
            ->put($this->url, ubahPayload($this->procurement, [
                'items' => [[
                    'procurement_detail_id' => $detailUsulan->procurement_detail_id,
                    'amount' => 10,
                    'unit_price' => 10000,
                ]],
            ]))
            ->assertRedirect($this->url);

        expect($this->procurement->fresh('details')->details)->toHaveCount(1)
            ->and($this->procurement->fresh('details')->details->firstWhere('item_id', $tambahan->item_id))->toBeNull();
    });

    it('menolak menghapus barang yang berasal dari usulan', function () {
        $usulan = setujuiUsulan($this->pejabat, buatUsulan($this->petugas, [
            ['item_id' => Item::factory()->create(['item_name' => 'Kertas HVS A4'])->item_id, 'amount_request' => 10],
            ['item_id' => Item::factory()->create(['item_name' => 'Tinta Printer'])->item_id, 'amount_request' => 5],
        ]));
        $procurement = buatRealisasi($this->pejabat, $usulan, Supplier::factory()->create());

        $this->actingAs($this->pejabat)
            ->put("/pengadaan/{$procurement->procurement_id}", ubahPayload($procurement, [
                'items' => [[
                    'procurement_detail_id' => $procurement->details->first()->procurement_detail_id,
                    'amount' => 10,
                    'unit_price' => 5000,
                ]],
            ]))
            ->assertSessionHasErrors('items');

        expect($procurement->fresh('details')->details)->toHaveCount(2);
    });

    it('menolak kiriman yang tidak mencakup seluruh baris', function () {
        $usulan = setujuiUsulan($this->pejabat, buatUsulan($this->petugas, [
            ['item_id' => Item::factory()->create(['item_name' => 'Kertas HVS A4'])->item_id, 'amount_request' => 10],
            ['item_id' => Item::factory()->create(['item_name' => 'Tinta Printer'])->item_id, 'amount_request' => 5],
        ]));
        $procurement = buatRealisasi($this->pejabat, $usulan, Supplier::factory()->create());

        $this->actingAs($this->pejabat)
            ->put("/pengadaan/{$procurement->procurement_id}", ubahPayload($procurement, [
                'items' => [[
                    'procurement_detail_id' => $procurement->details->first()->procurement_detail_id,
                    'amount' => 1,
                    'unit_price' => 1000,
                ]],
            ]))
            ->assertSessionHasErrors('items');
    });

    it('menolak baris milik realisasi lain', function () {
        $lain = realisasiMenunggu($this->pejabat, $this->petugas);

        $this->actingAs($this->pejabat)
            ->put($this->url, ubahPayload($this->procurement, [
                'items' => [[
                    'procurement_detail_id' => $lain->details->first()->procurement_detail_id,
                    'amount' => 1,
                    'unit_price' => 1000,
                ]],
            ]))
            ->assertSessionHasErrors('items.0.procurement_detail_id');
    });

    it('menolak penyedia yang sudah diarsipkan', function () {
        $arsip = Supplier::factory()->create(['is_active' => false]);

        $this->actingAs($this->pejabat)
            ->put($this->url, ubahPayload($this->procurement, ['supplier_id' => $arsip->supplier_id]))
            ->assertSessionHasErrors('supplier_id');
    });
});

describe('batas keadaan yang boleh diubah', function () {
    it('menolak mengubah realisasi yang sudah diterima', function () {
        $diterima = realisasiDiterima($this->pejabat, $this->petugas);

        $this->actingAs($this->pejabat)
            ->get("/pengadaan/{$diterima->procurement_id}/edit")
            ->assertRedirect("/pengadaan/{$diterima->procurement_id}")
            ->assertSessionHas('error');

        $this->actingAs($this->pejabat)
            ->from("/pengadaan/{$diterima->procurement_id}")
            ->put("/pengadaan/{$diterima->procurement_id}", ubahPayload($diterima->fresh('details')))
            ->assertSessionHas('error');
    });

    it('menolak mengubah realisasi yang sudah dibatalkan', function () {
        $this->actingAs($this->pejabat)->patch("{$this->url}/batal", pembatalanPayload());

        $this->actingAs($this->pejabat)
            ->get("{$this->url}/edit")
            ->assertRedirect($this->url)
            ->assertSessionHas('error');
    });

    it('menyembunyikan tombol Ubah setelah realisasi dibatalkan', function () {
        $this->actingAs($this->pejabat)->get($this->url)->assertOk()->assertSee('Ubah Realisasi');

        $this->actingAs($this->pejabat)->patch("{$this->url}/batal", pembatalanPayload());

        $this->actingAs($this->pejabat)->get($this->url)->assertOk()->assertDontSee('Ubah Realisasi');
    });
});

describe('notifikasi setelah pengubahan', function () {
    it('menyegarkan nama penyedia pada keadaan menunggu penerimaan', function () {
        // Isi RealisasiMenungguPenerimaan memuat nama penyedia, sehingga ia
        // basi begitu penyedianya diganti.
        $usulan = setujuiUsulan($this->pejabat, buatUsulan($this->petugas, [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]));
        $procurement = buatRealisasi(
            $this->pejabat,
            $usulan,
            Supplier::factory()->create(['supplier_name' => 'CV Penyedia Lama']),
        );

        $kabar = fn () => Notification::query()
            ->where('type', NotificationType::RealisasiMenungguPenerimaan)
            ->where('subject_id', $procurement->procurement_id)
            ->where('user_id', $this->petugas->user_id)
            ->first();

        expect($kabar()->body)->toContain('CV Penyedia Lama');

        $baru = Supplier::factory()->create(['supplier_name' => 'PT Penyedia Baru']);
        $this->actingAs($this->pejabat)->put(
            "/pengadaan/{$procurement->procurement_id}",
            ubahPayload($procurement->fresh('details'), ['supplier_id' => $baru->supplier_id]),
        );

        expect($kabar()->body)->toContain('PT Penyedia Baru')
            ->and($kabar()->body)->not->toContain('CV Penyedia Lama');
    });
});
