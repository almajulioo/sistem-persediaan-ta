<?php

namespace App\Models;

use App\Enums\NotificationLevel;
use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Satu notifikasi untuk satu penerima.
 *
 * Nama tabelnya `user_notifications`, bukan `notifications`, supaya tidak
 * bertabrakan dengan trait Notifiable bawaan Laravel yang sudah terpasang pada
 * model User. Lihat migrasi 2026_09_04_000001.
 *
 * Baris bersifat keadaan (lihat NotificationType::bersifatKeadaan) ditutup
 * dengan mengisi `resolved_at`, bukan dihapus, supaya keadaan yang kambuh dapat
 * menghidupkan kembali baris yang sama alih-alih menumpuk baris baru.
 */
class Notification extends Model
{
    use HasUuids;

    protected $table = 'user_notifications';

    protected $primaryKey = 'notification_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'type', 'level', 'title', 'body', 'action_url',
        'subject_type', 'subject_id', 'dedup_key', 'read_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'level' => NotificationLevel::class,
            'read_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function sudahDibaca(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Keadaan yang masih berlangsung, atau peristiwa yang memang tak pernah
     * ditutup.
     */
    public function masihAktif(): bool
    {
        return $this->resolved_at === null;
    }

    public function bersifatKeadaan(): bool
    {
        return $this->type->bersifatKeadaan();
    }

    /**
     * Waktu tampil: kapan keadaan ini mulai, atau kapan peristiwanya terjadi.
     */
    public function waktu(): string
    {
        return $this->created_at->diffForHumans();
    }

    public function scopeUntuk(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->user_id);
    }

    /**
     * Keadaan yang masih berlangsung dan peristiwa yang belum ditutup.
     */
    public function scopeAktif(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeBelumDibaca(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * Urut menurut kepentingan lebih dulu, baru menurut kebaruan.
     *
     * Bobot diambil dari enum, bukan dari urutan abjad nilai kolom, sehingga
     * "mendesak" tidak kalah dari "info" hanya karena huruf awalnya.
     */
    public function scopeTerpenting(Builder $query): Builder
    {
        $cases = implode(' ', array_map(
            fn (NotificationLevel $level) => sprintf("WHEN '%s' THEN %d", $level->value, $level->bobot()),
            NotificationLevel::cases(),
        ));

        return $query
            ->orderByRaw("CASE level {$cases} ELSE 99 END")
            ->latest('created_at');
    }

    /**
     * @param  array<string, string>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                NotificationType::tryFrom((string) ($filters['type'] ?? '')),
                fn (Builder $query, NotificationType $type) => $query->where('type', $type),
            )
            ->when(
                NotificationLevel::tryFrom((string) ($filters['level'] ?? '')),
                fn (Builder $query, NotificationLevel $level) => $query->where('level', $level),
            )
            ->when(
                ($filters['status'] ?? '') === 'aktif',
                fn (Builder $query) => $query->aktif(),
            )
            ->when(
                ($filters['status'] ?? '') === 'selesai',
                fn (Builder $query) => $query->whereNotNull('resolved_at'),
            )
            ->when(
                ($filters['status'] ?? '') === 'belum_dibaca',
                fn (Builder $query) => $query->belumDibaca(),
            );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_id');
    }
}
