<?php

namespace App\Http\Controllers\Pengadaan;

use App\Exceptions\ProcurementTidakSiapException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pengadaan\ConfirmProcurementReceiptRequest;
use App\Models\Procurement;
use App\Models\ProcurementReceipt;
use App\Support\ProcurementReceiptPoster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Konfirmasi penerimaan barang pengadaan — tugas Pengelola Gudang, terpisah
 * dari yang merealisasi (Pejabat Pengadaan). Lihat ADR 0008.
 *
 * Peristiwa sekali jadi: begitu dikonfirmasi, `ProcurementReceiptPoster`
 * menambah stok lewat StockLedger dan mengunci realisasi ke status Diterima.
 * Tidak ada rute ubah maupun hapus.
 */
class ProcurementReceiptController extends Controller
{
    public function create(Procurement $procurement): View|RedirectResponse
    {
        // Gudang tidak menerima barang yang belum ditandai dibelanjakan: fakta
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
                ->with('error', "{$procurement->judul()} belum disetujui, sudah dikonfirmasi diterima, atau dibatalkan.");
        }

        return view('pages.procurements.confirm-receipt', [
            'title' => "Konfirmasi Penerimaan — {$procurement->judul()}",
            'procurement' => $procurement->load(['supplier', 'details.item']),
            // Saran nomor berurut yang boleh ditimpa — format resmi BPK belum
            // dikonfirmasi lapangan, jadi tidak ada validasi format yang
            // dipaksakan. Lihat modul/pengadaan.md.
            //
            // Cacahnya dibatasi tahun berjalan, bukan sepanjang masa: penomoran
            // dokumen resmi dimulai ulang tiap tahun, dan saran yang keliru
            // justru berbahaya karena petugas mempercayainya.
            'saranNomorBast' => sprintf('%03d/BAST/%d', $this->urutanBastTahunIni(), now()->year),
        ]);
    }

    public function store(ConfirmProcurementReceiptRequest $request, Procurement $procurement): RedirectResponse
    {
        $data = $request->validated();

        try {
            ProcurementReceiptPoster::post(
                procurement: $procurement,
                diterima: array_map(fn ($jumlah) => (int) $jumlah, $data['received']),
                actor: $request->user(),
                bastNumber: $data['bast_number'],
                receivedAt: Carbon::parse($data['received_at']),
                notes: $data['notes'] ?? null,
            );
        } catch (ProcurementTidakSiapException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('procurements.show', $procurement)
            ->with('status', "Penerimaan {$procurement->judul()} berhasil dikonfirmasi.");
    }

    /**
     * Nomor urut BAST berikutnya dalam tahun berjalan.
     *
     * Memakai `received_at` (tanggal bisnis penerimaan), bukan `created_at`,
     * supaya penerimaan yang dicatat mundur tetap masuk hitungan tahun yang
     * benar.
     */
    private function urutanBastTahunIni(): int
    {
        return ProcurementReceipt::query()
            ->whereYear('received_at', now()->year)
            ->count() + 1;
    }
}
