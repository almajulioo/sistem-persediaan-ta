<?php

namespace App\Services;

use App\Enums\MutationType;
use App\Enums\UserRole;
use App\Exceptions\InsufficientStockException;
use App\Models\Request as ModelsRequest;
use App\Models\User;
use App\Support\NotificationScanner;
use App\Support\RequestDocumentGenerator;
use App\Support\StockLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RequestWorkflowService
{
    public function __construct(
        private readonly RequestDocumentGenerator $documentGenerator,
    ) {
    }

    public function respond(
        ModelsRequest $request,
        User $actor,
        string $action,
    ): ModelsRequest
    {
        if (! in_array($action, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('Aksi tidak valid.');
        }

        $request->loadMissing('user');

        if ($request->status !== 'submitted' && $request->hasItemProposals()) {
            throw new AuthorizationException('Permintaan belum dapat diproses karena masih ada usulan barang yang harus diselesaikan atau dihapus dari permintaan.');
        }

        $this->assertCanRespond($request, $actor, $action);

        DB::transaction(function () use ($request, $actor, $action): void {
            if ($action === 'reject') {
                $request->update(['status' => 'rejected']);

                return;
            }

            $nextStatus = $this->nextStatus($request, $actor);

            if ($nextStatus === 'processed_by_staff') {
                $this->fillZeroApprovedQuantities($request);
            }

            $request->update(['status' => $nextStatus]);

            if ($actor->hasRole(UserRole::KepalaUnit)
                || ($actor->hasRole(UserRole::KasubbagUmumTi)
                    && $actor->unit === 'Subbagian Umum dan TI'
                    && $nextStatus === 'approved_by_head')
                || ($actor->isAdmin() && $nextStatus === 'approved_by_head')) {
                $this->documentGenerator->approveNotaDinas($request, $actor);
            } elseif ($nextStatus === 'completed') {
                $this->recordIssuance($request, $actor);
                $this->documentGenerator->generateBast($request, $actor);
            }
        });

        $request = $request->fresh('user');
        NotificationScanner::catatPermintaanDiputuskan($request);

        return $request;
    }

    private function assertCanRespond(ModelsRequest $request, User $actor, string $action): void
    {
        $canRespond = match (true) {
            $actor->isAdmin() => in_array($request->status, [
                'submitted',
                'approved_by_head',
                'processed_by_staff',
                'in_issuance',
            ], true),
            $actor->hasRole(UserRole::KepalaUnit) =>
                $request->status === 'submitted'
                    && $actor->unit === $request->user?->unit,
            $actor->hasRole(UserRole::PengelolaGudang) =>
                in_array($request->status, ['approved_by_head', 'in_issuance'], true),
            $actor->hasRole(UserRole::KasubbagUmumTi) =>
                ($request->status === 'submitted' && $actor->unit === 'Subbagian Umum dan TI')
                || in_array($request->status, ['processed_by_staff', 'in_issuance'], true)
                || ($request->type === 'emergency' && $request->status === 'approved_by_head'),
            default => false,
        };

        $emergencyAlreadyApproved = $request->type === 'emergency'
            && $request->status === 'approved_by_head';

        if (! $canRespond || ($emergencyAlreadyApproved && $action === 'reject')) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk memproses permintaan ini.');
        }
    }

    private function nextStatus(ModelsRequest $request, User $actor): string
    {
        if ($actor->isAdmin()) {
            return match ($request->status) {
                'submitted' => 'approved_by_head',
                'approved_by_head' => 'processed_by_staff',
                'processed_by_staff' => 'in_issuance',
                default => 'completed',
            };
        }

        if ($actor->hasRole(UserRole::KepalaUnit)) {
            return 'approved_by_head';
        }

        if ($actor->hasRole(UserRole::KasubbagUmumTi)
            && $actor->unit === 'Subbagian Umum dan TI'
            && $request->status === 'submitted') {
            return 'approved_by_head';
        }

        if ($request->type === 'emergency' && $request->status === 'approved_by_head') {
            return 'completed';
        }

        if ($actor->hasRole(UserRole::PengelolaGudang)) {
            return $request->status === 'in_issuance'
                ? 'completed'
                : 'processed_by_staff';
        }

        return $request->status === 'processed_by_staff'
            ? 'in_issuance'
            : 'completed';
    }

    private function recordIssuance(ModelsRequest $request, User $actor): void
    {
        $request->loadMissing(['user', 'item.item']);

        foreach ($request->item as $detail) {
            if ($detail->item === null) {
                continue;
            }

            $amount = (int) $detail->quantity_approved;

            if ($amount <= 0) {
                continue;
            }

            $referenceNumber = $request->nd_number ?: $request->request_number;

            StockLedger::record(
                item: $detail->item,
                type: MutationType::Keluar,
                amount: $amount,
                recordedBy: $actor,
                reference: $request,
                referenceNumber: $referenceNumber,
                notes: "Penyerahan ke ".($request->user?->unit ?? 'unit pemohon')." sesuai {$referenceNumber}",
            );
        }
    }

    private function fillZeroApprovedQuantities(ModelsRequest $request): void
    {
        $request->loadMissing('item');

        foreach ($request->item as $detail) {
            if ((int) $detail->quantity_approved === 0) {
                $detail->loadMissing('item');
                if ($detail->item !== null && (int) $detail->quantity_requested > (int) $detail->item->stock_current) {
                    throw new InsufficientStockException(
                        $detail->item,
                        (int) $detail->quantity_requested,
                        (int) $detail->item->stock_current,
                    );
                }

                $detail->update([
                    'quantity_approved' => $detail->quantity_requested,
                ]);
            }
        }
    }

}
