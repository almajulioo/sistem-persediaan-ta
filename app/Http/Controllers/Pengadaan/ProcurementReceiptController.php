<?php

namespace App\Http\Controllers\Pengadaan;

use App\Exceptions\ProcurementTidakSiapException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pengadaan\StoreProcurementReceiptRequest;
use App\Http\Requests\Pengadaan\UpdateBastNumberRequest;
use App\Models\Procurement;
use App\Support\BastPengadaan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * BAST penerimaan barang pengadaan, dalam alur TTE (ADR 0051):
 *
 * 1. Pengelola Gudang mencatat hasil pemeriksaan — `create`/`store`.
 * 2. Salah satu pihak mengisi nomor BAST — `nomorBast`.
 * 3. Kedua pihak membubuhkan TTE — `tandatangani`. TTE kedua memasukkan stok.
 *
 * Pemeriksaan barang tetap tugas Pengelola Gudang, terpisah dari yang
 * merealisasi (ADR 0008). Logikanya ada di `BastPengadaan`; controller ini
 * hanya menerjemahkan penolakannya menjadi pesan.
 */
class ProcurementReceiptController extends Controller
{
    public function create(Procurement $procurement): View|RedirectResponse
    {
        // Gudang tidak memeriksa barang yang belum ditandai dibelanjakan: fakta
        // belanja dan fotonya dicatat oleh yang membelanjakan, bukan ditebak
        // oleh yang menerima (ADR 0035).
        if ($procurement->isProsesBelanja()) {
            return redirect()
                ->route('procurements.show', $procurement)
                ->with('error', ProcurementTidakSiapException::belumDibelanjakan()->getMessage());
        }

        if (! $procurement->isMenungguPenerimaan()) {
            return redirect()
                ->route('procurements.show', $procurement)
                ->with('error', "{$procurement->judul()} belum disetujui, sudah diterima, atau dibatalkan.");
        }

        return view('pages.procurements.confirm-receipt', [
            'title' => "Hasil Pemeriksaan — {$procurement->judul()}",
            'procurement' => $procurement->load(['supplier', 'details.item', 'receipt.signatures']),
        ]);
    }

    public function store(StoreProcurementReceiptRequest $request, Procurement $procurement): RedirectResponse
    {
        $data = $request->validated();

        try {
            BastPengadaan::catatPemeriksaan(
                procurement: $procurement,
                petugas: $request->user(),
                tanggal: Carbon::parse($data['received_at']),
                jumlah: array_map(fn ($jumlah) => (int) $jumlah, $data['received']),
                catatan: $data['notes'] ?? null,
            );
        } catch (ProcurementTidakSiapException $e) {
            return redirect()->route('procurements.show', $procurement)->with('error', $e->getMessage());
        }

        return redirect()
            ->route('procurements.show', $procurement)
            ->with('status', 'Hasil pemeriksaan disimpan.');
    }

    /**
     * Mengisi atau mengoreksi nomor BAST.
     *
     * BAST digital: sebelum TTE lengkap, dan mencabut TTE yang sudah ada bila
     * nomornya berubah. BAST lama: sampai pindaian bertanda tangan diunggah,
     * lalu langsung mencetaknya seperti ADR 0042.
     */
    public function nomorBast(UpdateBastNumberRequest $request, Procurement $procurement): RedirectResponse
    {
        if ($procurement->receipt === null) {
            return redirect()
                ->route('procurements.show', $procurement)
                ->with('error', 'Catat hasil pemeriksaan barang terlebih dahulu.');
        }

        if (! $procurement->nomorBastDapatDiubah()) {
            return redirect()
                ->route('procurements.show', $procurement)
                ->with('error', $procurement->bastLama()
                    ? 'Nomor BAST terkunci karena BAST bertanda tangan sudah diunggah.'
                    : 'Nomor BAST terkunci karena BAST sudah ditandatangani kedua pihak.');
        }

        $bastLama = $procurement->bastLama();

        BastPengadaan::isiNomor($procurement, $request->user(), $request->validated('bast_number'));

        if ($bastLama) {
            return redirect()->route('procurements.bast', $procurement);
        }

        return redirect()
            ->route('procurements.show', $procurement)
            ->with('status', 'Nomor BAST disimpan.');
    }

    public function tandatangani(Request $request, Procurement $procurement): RedirectResponse
    {
        try {
            $ttd = BastPengadaan::tandatangani($procurement, $request->user());
        } catch (ProcurementTidakSiapException $e) {
            return redirect()->route('procurements.show', $procurement)->with('error', $e->getMessage());
        }

        $procurement->refresh();

        return redirect()
            ->route('procurements.show', $procurement)
            ->with('status', $procurement->isDiterima()
                ? 'BAST lengkap ditandatangani. Stok sudah bertambah.'
                : "TTE sebagai {$ttd->party->label()} dibubuhkan.");
    }
}
