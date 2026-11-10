<?php

namespace App\Support;

use App\Models\Request as ModelsRequest;
use App\Models\RequestDocument;
use App\Models\RequestHistory;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;

class RequestDocumentGenerator
{
    public function generateNotaDinas(ModelsRequest $request): RequestDocument
    {
        $request->loadMissing(['user', 'item.itemProposal']);

        $filePath = 'nota-dinas/'.$request->request_id.'.pdf';
        $document = RequestDocument::query()->firstOrNew([
            'request_id' => $request->request_id,
            'document_type' => 'nota_dinas',
        ]);
        $document->fill([
            'user_id' => $request->user_id,
            'document_number' => $request->nd_number,
            'file_path' => $filePath,
            'approved_by_user_id' => null,
            'approved_at' => null,
            'verification_token_hash' => null,
        ]);
        $document->save();

        $this->renderNotaDinas($request, $document, null, null, null);

        return $document;
    }

    public function approveNotaDinas(ModelsRequest $request, User $approver): RequestDocument
    {
        $request->loadMissing(['user', 'item.itemProposal']);
        $document = RequestDocument::query()->firstOrNew([
            'request_id' => $request->request_id,
            'document_type' => 'nota_dinas',
        ]);
        $document->fill([
            'user_id' => $request->user_id,
            'file_path' => 'nota-dinas/'.$request->request_id.'.pdf',
        ]);

        return $this->issueApproval($request, $document, $approver, now());
    }

    /**
     * Mencetak ulang Nota Dinas setelah nomornya ditetapkan atau dibetulkan
     * Pengelola Gudang.
     * Persetujuan yang sudah ada dipertahankan, tetapi QR-nya diterbitkan
     * ulang karena token lama tidak tersimpan; QR pada PDF sebelumnya tidak
     * berlaku lagi.
     */
    public function renumberNotaDinas(ModelsRequest $request): RequestDocument
    {
        $request->loadMissing(['user', 'item.itemProposal']);
        $document = $request->requestDocuments()
            ->where('document_type', 'nota_dinas')
            ->with('approvedBy')
            ->first();

        if ($document === null) {
            return $this->generateNotaDinas($request);
        }

        if ($document->approvedBy === null) {
            $document->update(['document_number' => $request->nd_number]);
            $this->renderNotaDinas($request, $document, null, null, null);

            return $document;
        }

        return $this->issueApproval($request, $document, $document->approvedBy, $document->approved_at ?? now());
    }

    private function issueApproval(
        ModelsRequest $request,
        RequestDocument $document,
        User $approver,
        \Illuminate\Support\Carbon $approvedAt,
    ): RequestDocument {
        $verificationToken = Str::random(64);
        $document->fill([
            'document_number' => $request->nd_number,
            'approved_by_user_id' => $approver->user_id,
            'approved_at' => $approvedAt,
            'verification_token_hash' => hash('sha256', $verificationToken),
        ]);
        $document->save();

        $verificationUrl = route('request.nota-dinas.verify', [
            'requestDocument' => $document,
            'token' => $verificationToken,
        ]);
        $qrCodeDataUri = (new Builder)->build(
            data: $verificationUrl,
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 300,
            margin: 10,
        )->getDataUri();

        $this->renderNotaDinas($request, $document, $approver, $approvedAt, $qrCodeDataUri);

        return $document;
    }

    private function renderNotaDinas(
        ModelsRequest $request,
        RequestDocument $document,
        ?User $approver,
        ?\Illuminate\Support\Carbon $approvedAt,
        ?string $qrCodeDataUri,
    ): void {

        $pdf = Pdf::loadView('pdf.notadinas-permintaan', [
            'request' => $request,
            'approvedBy' => $approver,
            'approvedAt' => $approvedAt,
            'qrCodeDataUri' => $qrCodeDataUri,
            // Sebelum disetujui, nama penanda tangan diambil dari unit peminta.
            'kepalaUnit' => $approver === null ? $request->kepalaUnit() : null,
        ])->setPaper('a4', 'portrait');

        Storage::disk('local')->put($document->file_path, $pdf->output());
    }

    /**
     * BAST terbit saat Kasubbag memulai pengeluaran, dengan QR Mengetahui.
     * PIHAK KESATU dan tanggal serah terimanya masih kosong sampai barang
     * diserahkan (generateBast()).
     */
    public function startBast(ModelsRequest $request): RequestDocument
    {
        $request->loadMissing(['user', 'item']);
        $document = RequestDocument::query()->firstOrNew([
            'request_id' => $request->request_id,
            'document_type' => 'bast',
        ]);
        $document->fill([
            'user_id' => $request->user_id,
            'document_number' => $request->bast_number ?? $request->documentNumber('BAST'),
            'file_path' => 'bast-permintaan/'.$request->request_id.'.docx',
            'approved_by_user_id' => null,
            'approved_at' => null,
        ]);
        $document->save();

        $this->renderBast($request, $document, null, null);

        return $document;
    }

    public function generateBast(ModelsRequest $request, User $issuer): RequestDocument
    {
        $request->loadMissing(['user', 'item']);
        $issuedAt = now();
        $filePath = 'bast-permintaan/'.$request->request_id.'.docx';
        $document = RequestDocument::query()->firstOrNew([
            'request_id' => $request->request_id,
            'document_type' => 'bast',
        ]);
        $document->fill([
            'user_id' => $request->user_id,
            'document_number' => $request->bast_number ?? $request->documentNumber('BAST'),
            'file_path' => $filePath,
            'approved_by_user_id' => $issuer->user_id,
            'approved_at' => $issuedAt,
        ]);
        $document->save();

        $this->renderBast($request, $document, $issuer, $issuedAt);

        return $document;
    }

    /**
     * Mencetak ulang BAST supaya QR persetujuan berikutnya ikut tampil. Tanggal
     * dan PIHAK KESATU memakai yang tersimpan, bukan saat ini, karena berita
     * acaranya tetap yang dibuat saat barang diserahkan.
     */
    public function refreshBast(ModelsRequest $request): void
    {
        $document = $request->requestDocuments()
            ->where('document_type', 'bast')
            ->with('approvedBy')
            ->first();

        if ($document === null) {
            return;
        }

        $request->loadMissing(['user', 'item']);

        $this->renderBast($request, $document, $document->approvedBy, $document->approved_at);
    }

    /**
     * Tanpa $issuer berarti barang belum diserahkan: PIHAK KESATU dan tanggal
     * serah terima dibiarkan "……", sama dengan nomor Nota Dinas yang belum
     * diisi.
     */
    private function renderBast(
        ModelsRequest $request,
        RequestDocument $document,
        ?User $issuer,
        ?\Illuminate\Support\Carbon $issuedAt,
    ): void {
        $kosong = '……';
        $templatePath = resource_path('document/template-bast-persediaan.docx');
        $temporaryBasePath = tempnam(sys_get_temp_dir(), 'bast-');
        if ($temporaryBasePath === false) {
            throw new RuntimeException('Lokasi sementara untuk membuat BAST tidak dapat disiapkan.');
        }
        unlink($temporaryBasePath);

        $docxPath = $temporaryBasePath.'.docx';

        try {
            $template = new TemplateProcessor($templatePath);
            $requiredVariables = [
                'document_number',
                'issued_day',
                'issued_date',
                'recipient_name',
                'recipient_nip',
                'recipient_position',
                'recipient_unit',
                'issuer_name',
                'issuer_nip',
                'issuer_position',
                'issuer_unit',
                'request_number',
                'item_list',
            ];
            $missingVariables = array_diff($requiredVariables, $template->getVariables());
            if ($missingVariables !== []) {
                throw new RuntimeException(
                    'Template BAST belum lengkap. Placeholder yang belum tersedia: ${'
                    .implode('}, ${', $missingVariables).'}',
                );
            }

            // PhpWord tidak meng-escape isi secara bawaan; nama barang atau
            // pengguna yang memuat "&" atau "<" merusak DOCX-nya.
            $template->setValues(array_map(self::xml(...), [
                'document_number' => $document->document_number,
                'issued_day' => $issuedAt?->translatedFormat('l') ?? $kosong,
                'issued_date' => $issuedAt?->translatedFormat('j F Y') ?? $kosong,
                'recipient_name' => $request->user?->fullname ?? '',
                'recipient_nip' => $request->user?->nip ?? '',
                'recipient_position' => $request->user?->position ?? 'Penerima Barang',
                'recipient_unit' => $request->user?->unit?->label() ?? '',
                'issuer_name' => $issuer === null ? $kosong : ($issuer->fullname ?? ''),
                'issuer_nip' => $issuer === null ? $kosong : ($issuer->nip ?? ''),
                'issuer_position' => $issuer === null ? $kosong : ($issuer->position ?? 'Pengelola Gudang Persediaan'),
                'issuer_unit' => $issuer === null ? $kosong : ($issuer->unit?->label() ?? 'BPK Perwakilan Provinsi Lampung'),
                'request_number' => $request->request_number,
            ]));

        // Hanya barang yang benar-benar diserahkan; jumlah 0 berarti barang
        // itu tidak diberikan dan stoknya memang tidak dikeluarkan.
        $details = $request->item->filter(fn ($detail) => (int) $detail->quantity_approved > 0)->values();
        if ($details->isEmpty()) {
            throw new RuntimeException('BAST tidak dapat dibuat karena tidak memiliki rincian barang.');
        }

        $itemList = $details
            ->map(fn ($detail) => trim(sprintf(
                '%s %s %s',
                number_format($detail->quantity_approved, 0, ',', '.'),
                $detail->item?->unit?->label() ?? '',
                $detail->item?->item_name ?? 'Barang tidak dikenal',
            )))
            ->implode(', ');

        $template->setValue('item_list', self::xml($itemList));

            $template->saveAs($docxPath);
            (new BastQrStamper)->stamp($docxPath, $this->bastQrCodes($request));
            $docx = file_get_contents($docxPath);
            if ($docx === false || ! Storage::disk('local')->put($document->file_path, $docx)) {
                throw new RuntimeException('DOCX BAST tidak dapat disimpan ke penyimpanan aplikasi.');
            }
        } finally {
            if (is_file($docxPath)) {
                unlink($docxPath);
            }
        }
    }

    private static function xml(?string $teks): string
    {
        return htmlspecialchars((string) $teks, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * QR tiap persetujuan yang sudah terjadi. Tautannya bertanda tangan, bukan
     * token acak seperti Nota Dinas: BAST dicetak ulang saat dikonfirmasi, dan
     * QR pada salinan yang sudah terlanjur diunduh harus tetap berlaku. Relatif
     * supaya tidak ikut batal bila alamat aplikasinya berubah.
     *
     * @return array<string, string> kolom => isi PNG
     */
    private function bastQrCodes(ModelsRequest $request): array
    {
        return array_map(
            fn (RequestHistory $history): string => (new Builder)->build(
                data: url(URL::signedRoute('request.bast.verify', ['requestHistory' => $history], absolute: false)),
                // Rendah supaya modulnya lebih besar: QR tercetak kecil di
                // kolom tanda tangan, dan lebih penting mudah terpindai.
                errorCorrectionLevel: ErrorCorrectionLevel::Low,
                size: 300,
                margin: 10,
            )->getString(),
            $request->persetujuanBast(),
        );
    }
}
