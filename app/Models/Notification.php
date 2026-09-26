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
 * Tidak ada baris yang hidup dalam keadaan "selesai": keadaan yang pulih
 * langsung dihapus, dan peristiwa dihapus setelah berumur tujuh hari
 * (ADR 0036). Karena itu setiap baris yang ada adalah notifikasi yang masih
 * berlaku, dan pembedanya hanya satu — sudah dibaca atau belum.
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
        'subject_type', 'subject_id', 'dedup_key', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'level' => NotificationLevel::class,
            'read_at' => 'datetime',
        ];
    }

    public function sudahDibaca(): bool
    {
        return $this->read_at !== null;
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
     * Isi lonceng: yang sudah dibaca menyingkir dari sana, tetapi tetap
     * tersedia di halaman notifikasi.
     */
    public function scopeBelumDibaca(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeSudahDibaca(Builder $query): Builder
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * Isi bagian "Perlu Tindakan" pada lonceng.
     */
    public function scopeKeadaan(Builder $query): Builder
    {
        return $query->whereIn('type', NotificationType::keadaan());
    }

    /**
     * Isi bagian "Aktivitas Terbaru" pada lonceng.
     */
    public function scopePeristiwa(Builder $query): Builder
    {
        return $query->whereIn('type', NotificationType::peristiwa());
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
            ->terbaru();
    }

    /**
     * Urut menurut kebaruan saja — untuk peristiwa, yang dibaca sebagai
     * linimasa, bukan antrean pekerjaan.
     *
     * UUID dipakai sebagai penentu seri karena `HasUuids` membangkitkannya
     * berurut waktu: dua baris yang lahir pada detik yang sama tetap punya
     * urutan yang pasti.
     */
    public function scopeTerbaru(Builder $query): Builder
    {
        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('notification_id');
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
                ($filters['status'] ?? '') === 'belum_dibaca',
                fn (Builder $query) => $query->belumDibaca(),
            )
            ->when(
                ($filters['status'] ?? '') === 'sudah_dibaca',
                fn (Builder $query) => $query->sudahDibaca(),
            )
            ->when(
                ($filters['sifat'] ?? '') === 'keadaan',
                fn (Builder $query) => $query->keadaan(),
            )
            ->when(
                ($filters['sifat'] ?? '') === 'peristiwa',
                fn (Builder $query) => $query->peristiwa(),
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
