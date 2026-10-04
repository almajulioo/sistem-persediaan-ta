<?php

namespace App\Http\Controllers\Pengadaan;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\ProcurementStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pengadaan\ApproveProcurementRealizationRequest;
use App\Http\Requests\Pengadaan\RejectProcurementRealizationRequest;
use App\Models\Procurement;
use App\Support\AuditLogger;
use App\Support\NotificationScanner;
use Illuminate\Http\RedirectResponse;

/**
 * Persetujuan realisasi pengadaan oleh Kasubbag Umum dan TI.
 *
 * Tahap keempat dari alur yang sebelumnya bertangan tiga: Pengelola Gudang
 * mengusulkan, Pejabat Pengadaan menyetujui usulan lalu membuat realisasi,
 * **Kasubbag Umum dan TI menyetujui realisasi**, dan Pengelola Gudang menerima
 * barangnya. Lihat ADR 0016 dan ADR 0030.
 *
 * Dipisah dari `ProcurementController` mengikuti preseden satu kelas per tahap
 * — seperti `ProcurementReceiptController` untuk penerimaan.
 *
 * Tidak ada aksi `create`/`edit` di sini: yang dinilai adalah dokumen yang sudah
 * tersaji di halaman detail realisasi, sehingga keputusannya cukup dua rute
 * PATCH tanpa layar tersendiri.
 */
class ProcurementApprovalController extends Controller
{
    public function approve(ApproveProcurementRealizationRequest $request, Procurement $procurement): RedirectResponse
    {
        if (! $procurement->isMenungguPersetujuan()) {
            return back()->with('error', "{$procurement->judul()} sudah diputuskan sebelumnya.");
        }

        // Persetujuan membuka tahap belanja (ADR 0035). Realisasi yang sudah
        // dibelanjakan lalu diubah dan diajukan ulang tidak disuruh belanja
        // lagi: mengubah dokumen mengulang penilaian, bukan mengulang belanja.
        $sudahDibelanjakan = $procurement->sudahDibelanjakan();

        $procurement->forceFill([
            'status' => $sudahDibelanjakan ? ProcurementStatus::MenungguPenerimaan : ProcurementStatus::ProsesBelanja,
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()->user_id,
            'review_note' => $request->input('review_note'),
        ])->save();

        AuditLogger::record(
            user: $request->user(),
            activity: "Menyetujui realisasi pengadaan: {$procurement->judul()}",
            module: AuditModule::Pengadaan,
            event: AuditEvent::Setuju,
            subject: $procurement,
        );
        NotificationScanner::catatRealisasiDiputuskan(
            $procurement->fresh(['user', 'supplier', 'receipt']),
            $request->user(),
        );

        return redirect()
            ->route('procurements.show', $procurement)
            ->with('status', "{$procurement->judul()} disetujui.");
    }

    public function reject(RejectProcurementRealizationRequest $request, Procurement $procurement): RedirectResponse
    {
        if (! $procurement->isMenungguPersetujuan()) {
            return back()->with('error', "{$procurement->judul()} sudah diputuskan sebelumnya.");
        }

        $procurement->forceFill([
            'status' => ProcurementStatus::Ditolak,
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()->user_id,
            'review_note' => $request->validated()['review_note'],
        ])->save();

        AuditLogger::record(
            user: $request->user(),
            activity: "Menolak realisasi pengadaan: {$procurement->judul()}",
            module: AuditModule::Pengadaan,
            event: AuditEvent::Tolak,
            subject: $procurement,
        );
        NotificationScanner::catatRealisasiDiputuskan(
            $procurement->fresh(['user', 'supplier', 'receipt']),
            $request->user(),
        );

        return redirect()
            ->route('procurements.show', $procurement)
            ->with('status', "{$procurement->judul()} ditolak.");
    }
}
