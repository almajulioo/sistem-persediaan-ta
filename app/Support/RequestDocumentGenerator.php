<?php

namespace App\Support;

use App\Models\Request as ModelsRequest;
use App\Models\RequestDocument;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RequestDocumentGenerator
{
    public function generateNotaDinas(ModelsRequest $request, User $approver): RequestDocument
    {
        $request->loadMissing(['user', 'item']);
        $approvedAt = now();
        $verificationToken = Str::random(64);

        $filePath = 'nota-dinas/'.$request->request_id.'.pdf';
        $document = RequestDocument::query()->firstOrNew([
            'request_id' => $request->request_id,
            'document_type' => 'nota_dinas',
        ]);
        $document->fill([
            'user_id' => $request->user_id,
            'document_number' => $request->request_number,
            'file_path' => $filePath,
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

        $pdf = Pdf::loadView('pdf.notadinas-permintaan', [
            'request' => $request,
            'approvedBy' => $approver,
            'approvedAt' => $approvedAt,
            'qrCodeDataUri' => $qrCodeDataUri,
        ])->setPaper('a4', 'portrait');

        Storage::disk('local')->put($filePath, $pdf->output());

        return $document;
    }

    public function generateBast(ModelsRequest $request, User $issuer): RequestDocument
    {
        $request->loadMissing(['user', 'item']);
        $issuedAt = now();
        $filePath = 'bast-permintaan/'.$request->request_id.'.pdf';
        $document = RequestDocument::query()->firstOrNew([
            'request_id' => $request->request_id,
            'document_type' => 'bast',
        ]);
        $document->fill([
            'user_id' => $request->user_id,
            'document_number' => 'BAST-'.$request->request_number,
            'file_path' => $filePath,
            'approved_by_user_id' => $issuer->user_id,
            'approved_at' => $issuedAt,
        ]);
        $document->save();

        $pdf = Pdf::loadView('pdf.bast-permintaan', [
            'request' => $request,
            'issuer' => $issuer,
            'issuedAt' => $issuedAt,
            'document' => $document,
        ])->setPaper('a4', 'portrait');

        Storage::disk('local')->put($filePath, $pdf->output());

        return $document;
    }
}
