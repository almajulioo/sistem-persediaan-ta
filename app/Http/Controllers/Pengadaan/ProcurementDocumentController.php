<?php

namespace App\Http\Controllers\Pengadaan;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pengadaan\StoreProcurementDocumentRequest;
use App\Models\Procurement;
use App\Models\ProcurementDocument;
use App\Support\AuditLogger;
use App\Support\DocumentStorage;
use App\Support\NotificationScanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Berkas pendukung pengadaan: unggah, unduh, hapus.
 *
 * Tidak ada satu pun tautan langsung ke berkasnya. Seluruh unduhan melewati
 * rute ini supaya autentikasi dan gate `akses-pengadaan` benar-benar berlaku
 * — disk penyimpanannya privat justru agar hal itu tidak dapat dilangkahi.
 * Lihat ADR 0018.
 */
class ProcurementDocumentController extends Controller
{
    public function store(StoreProcurementDocumentRequest $request, Procurement $procurement): RedirectResponse
    {
        $file = $request->file('file');
        $jenis = $request->jenis();

        $document = $procurement->documents()->create([
            'type' => $jenis,
            'file_path' => DocumentStorage::simpan($file, $procurement),
            'original_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'uploaded_by' => $request->user()->user_id,
            'notes' => $request->input('notes'),
        ]);

        AuditLogger::record(
            user: $request->user(),
            activity: "Mengunggah {$jenis->label()} pada {$procurement->judul()}",
            module: AuditModule::DokumenPengadaan,
            event: AuditEvent::Unggah,
            subject: $document,
        );

        // Berkas BAST yang baru masuk menutup keadaan BastBelumDiunggah tanpa
        // pengguna perlu mengklik apa pun — sifat keadaan pada ADR 0013.
        NotificationScanner::periksaRealisasi($procurement->fresh(['supplier', 'receipt']));

        return back()->with('status', "{$jenis->label()} berhasil diunggah.");
    }

    public function download(Request $request, Procurement $procurement, ProcurementDocument $document): StreamedResponse|RedirectResponse
    {
        $this->pastikanMilik($procurement, $document);

        // Baris basis data dan berkas fisik dapat terpisah, misalnya setelah
        // pemulihan cadangan yang tidak menyertakan storage/.
        if (! DocumentStorage::ada($document)) {
            return back()->with('error', "Berkas {$document->original_name} tidak ditemukan di penyimpanan.");
        }

        // Satu satunya aksi baca yang ikut dicatat. Berkas pengadaan adalah
        // bukti pada disk privat — kontrak dan BAST bertanda tangan — sehingga
        // siapa yang membacanya sama layaknya ditelusuri dengan siapa yang
        // mengunggahnya. Dicatat sebelum berkas dikirim, karena unduhan yang
        // terputus di tengah jalan tetap berarti isinya sudah terbuka.
        AuditLogger::record(
            user: $request->user(),
            activity: "Mengunduh {$document->type->label()} ({$document->original_name}) pada {$procurement->judul()}",
            module: AuditModule::DokumenPengadaan,
            event: AuditEvent::Unduh,
            subject: $document,
        );

        return DocumentStorage::unduh($document);
    }

    /**
     * Menghapus berkas yang salah unggah.
     *
     * Dibatasi pengunggahnya sendiri dan Admin. Berkas pengadaan adalah bukti,
     * bukan data kerja: siapa pun yang boleh membacanya tidak dengan sendirinya
     * boleh membuangnya. Yang diperbaiki di sini hanyalah salah unggah oleh
     * orang yang sama — kekeliruan yang tidak masuk akal diperlakukan sebagai
     * arsip permanen.
     */
    public function destroy(Request $request, Procurement $procurement, ProcurementDocument $document): RedirectResponse
    {
        $this->pastikanMilik($procurement, $document);

        $user = $request->user();

        if ($document->uploaded_by !== $user->user_id && ! $user->isAdmin()) {
            return back()->with('error', 'Hanya pengunggah berkas atau Admin yang dapat menghapusnya.');
        }

        $jenis = $document->type;
        $nama = $document->original_name;

        // Berkas fisik lebih dulu: baris yatim tanpa berkas masih dapat
        // diperbaiki, sedangkan berkas yatim tanpa baris tidak akan pernah
        // terhapus oleh siapa pun.
        DocumentStorage::hapus($document);
        $document->delete();

        AuditLogger::record(
            user: $user,
            activity: "Menghapus {$jenis->label()} ({$nama}) pada {$procurement->judul()}",
            module: AuditModule::DokumenPengadaan,
            event: AuditEvent::Hapus,
        );

        // BAST yang dihapus membuat keadaan BastBelumDiunggah berlaku lagi.
        NotificationScanner::periksaRealisasi($procurement->fresh(['supplier', 'receipt']));

        return back()->with('status', "{$jenis->label()} berhasil dihapus.");
    }

    /**
     * Berkas yang tidak menempel pada pengadaan di URL diperlakukan sebagai
     * tidak ada, bukan sebagai galat otorisasi — supaya URL tidak dapat
     * dipakai menebak keberadaan berkas milik pengadaan lain.
     */
    private function pastikanMilik(Procurement $procurement, ProcurementDocument $document): void
    {
        abort_unless($document->procurement_id === $procurement->procurement_id, 404);
    }
}
