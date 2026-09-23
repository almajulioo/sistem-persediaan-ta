<?php

namespace App\Http\Controllers\Pengadaan;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\ProcurementDocumentType;
use App\Enums\ProcurementStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pengadaan\MarkProcurementPurchasedRequest;
use App\Models\Procurement;
use App\Models\ProcurementDocument;
use App\Support\AuditLogger;
use App\Support\DocumentStorage;
use App\Support\NotificationScanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Menandai realisasi pengadaan sudah dibelanjakan — tugas Pejabat Pengadaan,
 * di antara persetujuan Kasubbag Umum dan TI dan penerimaan oleh Pengelola
 * Gudang. Lihat ADR 0035.
 *
 * Dipisah dari `ProcurementController` mengikuti preseden satu kelas per tahap,
 * seperti `ProcurementApprovalController` dan `ProcurementReceiptController`.
 *
 * Tidak ada rute "batal dibelanjakan". Belanja adalah fakta fisik; yang keliru
 * diperbaiki lewat ubah realisasi, dan realisasi yang tidak jadi diterima
 * keluar lewat pembatalan.
 */
class ProcurementPurchaseController extends Controller
{
    public function store(MarkProcurementPurchasedRequest $request, Procurement $procurement): RedirectResponse
    {
        if (! $procurement->isProsesBelanja()) {
            return back()->with(
                'error',
                "{$procurement->judul()} tidak sedang dalam proses belanja, sehingga tidak dapat ditandai sudah dibelanjakan.",
            );
        }

        $data = $request->validated();
        $user = $request->user();
        $tanggal = Carbon::parse($data['purchased_at']);

        /** @var array<int, UploadedFile> $foto */
        $foto = $request->file('photos') ?? [];

        // Berkas ditulis lebih dulu karena disk tidak ikut transaksi basis
        // data. Bila transaksinya gagal, berkas yang sudah tertulis dibuang
        // lagi — berkas yatim tanpa baris tidak akan pernah terhapus siapa pun.
        $tersimpan = array_map(
            fn (UploadedFile $file) => ['file' => $file, 'path' => DocumentStorage::simpan($file, $procurement)],
            $foto,
        );

        try {
            $dokumen = DB::transaction(function () use ($procurement, $tersimpan, $data, $user, $tanggal) {
                $dokumen = array_map(
                    fn (array $berkas) => $procurement->documents()->create([
                        'type' => ProcurementDocumentType::FotoBelanja,
                        'file_path' => $berkas['path'],
                        'original_name' => $berkas['file']->getClientOriginalName(),
                        'file_size' => $berkas['file']->getSize(),
                        'mime_type' => $berkas['file']->getMimeType(),
                        'uploaded_by' => $user->user_id,
                    ]),
                    $tersimpan,
                );

                $procurement->forceFill([
                    'status' => ProcurementStatus::MenungguPenerimaan,
                    'purchased_at' => $tanggal,
                    'purchased_by' => $user->user_id,
                    'purchase_note' => $data['purchase_note'] ?? null,
                ])->save();

                return $dokumen;
            });
        } catch (Throwable $e) {
            foreach ($tersimpan as $berkas) {
                DocumentStorage::hapusBerkas($berkas['path']);
            }

            throw $e;
        }

        // Satu baris per foto, sejalan dengan unggahan lewat Berkas
        // Pendukung: unduhan dan penghapusan foto itu kelak tercatat pada
        // modul yang sama, dan tiap baris itu perlu pasangan unggahnya.
        foreach ($dokumen as $document) {
            /** @var ProcurementDocument $document */
            AuditLogger::record(
                user: $user,
                activity: "Mengunggah {$document->type->label()} pada {$procurement->judul()}",
                module: AuditModule::DokumenPengadaan,
                event: AuditEvent::Unggah,
                subject: $document,
            );
        }

        AuditLogger::record(
            user: $user,
            activity: "Menandai sudah dibelanjakan: {$procurement->judul()} (tanggal belanja {$tanggal->translatedFormat('j F Y')})",
            module: AuditModule::Pengadaan,
            event: AuditEvent::UbahStatus,
            subject: $procurement,
        );

        // Menutup RealisasiBelumDibelanjakan dan menerbitkan
        // RealisasiMenungguPenerimaan ke gudang — baru sekarang barangnya
        // memang layak ditunggu.
        NotificationScanner::periksaRealisasi($procurement->fresh(['supplier', 'receipt']));

        return redirect()
            ->route('procurements.show', $procurement)
            ->with('status', "{$procurement->judul()} ditandai sudah dibelanjakan. Pengelola Gudang kini menunggu barangnya untuk dikonfirmasi diterima.");
    }
}
