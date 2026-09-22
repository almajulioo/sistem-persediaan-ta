<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Request as ModelsRequest;
use App\Models\User;
use App\Support\NotificationScanner;
use App\Support\RequestDocumentGenerator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RequestWorkflowService
{
    public function __construct(
        private readonly RequestDocumentGenerator $documentGenerator,
    ) {
    }

    public function respond(ModelsRequest $request, User $actor, string $action): ModelsRequest
    {
        if (! in_array($action, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('Aksi tidak valid.');
        }

        $request->loadMissing('user');
        $this->assertCanRespond($request, $actor, $action);

        DB::transaction(function () use ($request, $actor, $action): void {
            if ($action === 'reject') {
                $request->update(['status' => 'rejected']);

                return;
            }

            $nextStatus = $this->nextStatus($request, $actor);
            $request->update(['status' => $nextStatus]);

            if ($actor->hasRole(UserRole::KepalaUnit)
                || ($actor->isAdmin() && $nextStatus === 'approved_by_head')) {
                $this->documentGenerator->generateNotaDinas($request, $actor);
            } elseif ($nextStatus === 'completed') {
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
                in_array($request->status, ['processed_by_staff', 'in_issuance'], true)
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
}
