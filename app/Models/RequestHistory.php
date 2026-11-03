<?php

namespace App\Models;

use App\Enums\RequestHistoryAction;
use App\Models\Request as ModelsRequest;
use App\Support\NotificationScanner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu langkah pada riwayat permintaan beserta catatan pelakunya.
 *
 * Baris ditulis hanya lewat catat(), yang sekaligus mengabari pemohon. Di
 * dalam transaksi pun aman: notifikasi tersimpan di basis data yang sama,
 * jadi ikut dibatalkan bila transaksinya gagal.
 */
class RequestHistory extends Model
{
    protected $primaryKey = 'request_history_id';

    protected $fillable = [
        'request_id',
        'user_id',
        'action',
        'from_status',
        'to_status',
        'note',
        'quantity_changes',
    ];

    protected function casts(): array
    {
        return [
            'action' => RequestHistoryAction::class,
            'quantity_changes' => 'array',
        ];
    }

    /**
     * @param  list<array{item_name: string, before: int, after: int}>|null  $changes
     */
    public static function catat(
        ModelsRequest $request,
        ?User $actor,
        RequestHistoryAction $action,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $note = null,
        ?array $changes = null,
    ): self {
        $note = trim((string) $note);

        $history = self::create([
            'request_id' => $request->request_id,
            'user_id' => $actor?->user_id,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note === '' ? null : $note,
            'quantity_changes' => $changes === [] ? null : $changes,
        ]);

        $history->setRelation('request', $request);
        $history->setRelation('user', $actor);
        NotificationScanner::catatRiwayatPermintaan($history);

        return $history;
    }

    /**
     * Judul langkah. Persetujuan disebut dengan status yang dicapainya, mis.
     * "Disetujui Kepala Subbagian" atau "Selesai", karena itulah yang dipahami
     * pemohon. Sebutan penyetuju mengikuti unit pemohon permintaannya.
     */
    public function judul(): string
    {
        $label = fn (?string $status): string => $this->request?->labelStatusUntuk($status)
            ?? ModelsRequest::labelStatus($status);

        return match ($this->action) {
            RequestHistoryAction::Disetujui => $label($this->to_status),
            RequestHistoryAction::Ditolak => $this->from_status !== null
                ? 'Ditolak pada tahap '.$label($this->from_status)
                : 'Ditolak',
            RequestHistoryAction::Dikembalikan => sprintf(
                'Status dikembalikan dari %s ke %s',
                $label($this->from_status),
                $label($this->to_status),
            ),
            default => $this->action->label(),
        };
    }

    /**
     * Rincian perubahan jumlah dalam kalimat, mis. "Kertas A4: 10 → 5".
     *
     * @return list<string>
     */
    public function rincianPerubahan(): array
    {
        return array_map(
            fn (array $change) => sprintf(
                '%s: %d → %s',
                $change['item_name'],
                $change['before'],
                $change['after'] === 0 ? '0 (tidak diberikan)' : $change['after'],
            ),
            $this->quantity_changes ?? [],
        );
    }

    /**
     * Isi notifikasi untuk pemohon: pelaku, rincian, dan catatannya.
     */
    public function uraian(): string
    {
        $pelaku = match (true) {
            $this->action->olehSistem() => 'Sistem',
            $this->user !== null => "{$this->user->fullname} ({$this->user->sebutanJabatan()})",
            default => 'Petugas',
        };

        return collect([
            "Oleh {$pelaku}.",
            $this->rincianPerubahan() === [] ? null : implode('; ', $this->rincianPerubahan()).'.',
            $this->note !== null ? "Catatan: {$this->note}" : null,
        ])->filter()->implode(' ');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ModelsRequest::class, 'request_id', 'request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
