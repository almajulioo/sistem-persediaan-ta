<?php

namespace App\Support;

use App\Models\Request as ModelsRequest;
use App\Models\RequestDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class RequestDocumentGenerator
{
    public function generateNotaDinas(ModelsRequest $request): RequestDocument
    {
        $request->loadMissing(['user', 'item']);

        $filePath = 'nota-dinas/'.$request->request_id.'.pdf';
        $pdf = Pdf::loadView('pdf.notadinas-permintaan', [
            'request' => $request,
        ])->setPaper('a4', 'portrait');

        Storage::disk('local')->put($filePath, $pdf->output());

        return RequestDocument::updateOrCreate(
            [
                'request_id' => $request->request_id,
                'document_type' => 'nota_dinas',
            ],
            [
                'user_id' => $request->user_id,
                'document_number' => $request->request_number,
                'file_path' => $filePath,
                'mime_type' => 'application/pdf',
            ],
        );
    }
}
