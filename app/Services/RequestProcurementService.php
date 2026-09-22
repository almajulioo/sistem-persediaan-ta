<?php

namespace App\Services;

use App\Enums\ProcurementRequestSource;
use App\Enums\ProcurementRequestStatus;
use App\Models\ProcurementRequest;
use App\Models\Request as ModelsRequest;
use Illuminate\Support\Facades\DB;

class RequestProcurementService
{
    public function createForShortage(ModelsRequest $request): ?ProcurementRequest
    {
        return DB::transaction(function () use ($request): ?ProcurementRequest {
            $request->loadMissing('item');

            $shortages = $request->item
                ->filter(fn ($detail): bool => $detail->item !== null)
                ->mapWithKeys(function ($detail): array {
                    $shortage = (int) $detail->quantity_requested - (int) $detail->item->stock_current;

                    return $shortage > 0 ? [$detail->item_id => $shortage] : [];
                });

            if ($shortages->isEmpty()) {
                return null;
            }

            $existing = ProcurementRequest::query()
                ->where('source', ProcurementRequestSource::DariPermintaan)
                ->where('reference_type', 'permintaan')
                ->where('reference_id', $request->request_id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $procurementRequest = ProcurementRequest::create([
                'user_id' => $request->user_id,
                'source' => ProcurementRequestSource::DariPermintaan,
                'reference_type' => 'permintaan',
                'reference_id' => $request->request_id,
                'status' => ProcurementRequestStatus::Diajukan,
            ]);

            $procurementRequest->details()->createMany(
                $shortages->map(fn (int $amount, string $itemId): array => [
                    'item_id' => $itemId,
                    'amount_request' => $amount,
                    'is_auto' => true,
                ])->values()->all(),
            );

            return $procurementRequest;
        });
    }
}