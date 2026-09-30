<?php

namespace App\Services;

use App\Enums\ProcurementRequestSource;
use App\Enums\ProcurementRequestStatus;
use App\Models\ProcurementRequest;
use App\Models\Request as ModelsRequest;
use App\Support\ProcurementDraftScanner;
use Illuminate\Support\Facades\DB;

class RequestProcurementService
{
    public function createForShortage(ModelsRequest $request): ?ProcurementRequest
    {
        $procurementDraft = DB::transaction(function () use ($request): ?ProcurementRequest {
            $request->loadMissing('item');

            $shortages = $request->item
                ->filter(fn ($detail): bool => $detail->item !== null)
                ->groupBy('item_id')
                ->mapWithKeys(function ($details, string $itemId): array {
                    $detail = $details->first();
                    $shortage = (int) $details->sum('quantity_requested') - (int) $detail->item->stock_current;

                    return $shortage > 0 ? [$itemId => $shortage] : [];
                });

            if ($shortages->isEmpty()) {
                return null;
            }

            $procurementDraft = ProcurementRequest::query()
                ->draf()
                ->lockForUpdate()
                ->first();

            $procurementDraft ??= ProcurementRequest::create([
                'user_id' => null,
                'source' => ProcurementRequestSource::DariPermintaan,
                'status' => ProcurementRequestStatus::Draf,
            ]);

            foreach ($shortages as $itemId => $amount) {
                $detail = $procurementDraft->details()
                    ->where('item_id', $itemId)
                    ->first();

                if ($detail === null) {
                    $procurementDraft->details()->create([
                        'item_id' => $itemId,
                        'amount_request' => $amount,
                        'is_auto' => false,
                    ]);
                } else {
                    $detail->update([
                        'amount_request' => (int) $detail->amount_request + $amount,
                        'is_auto' => false,
                    ]);
                }
            }

            return $procurementDraft;
        });

        ProcurementDraftScanner::sinkronkanNotifikasi();

        return $procurementDraft;
    }
}