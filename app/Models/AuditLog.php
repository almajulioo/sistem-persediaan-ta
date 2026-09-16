<?php

namespace App\Models;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Satu baris jejak audit.
 *
 * Bersifat append-only: tidak pernah diubah maupun dihapus, dan tidak punya
 * rute tulis sama sekali. Kolom `activity` adalah kalimat yang dibaca manusia;
 * `module`, `event`, dan `actor_role` adalah kolom terstruktur pendampingnya
 * yang membuat log dapat disaring. Lihat ADR 0034.
 */
class AuditLog extends Model
{
    use HasUuids;

    protected $primaryKey = 'log_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id', 'activity', 'module', 'event', 'actor_role',
        'ip_address', 'subject_type', 'subject_id', 'timestamp',
    ];

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
            'module' => AuditModule::class,
            'event' => AuditEvent::class,
            'actor_role' => UserRole::class,
        ];
    }

    /**
     * Nama pelaku. Baris lama dari sebelum ADR 0034 bisa saja kehilangan
     * relasinya; jangan sampai satu baris cacat menggagalkan seluruh layar.
     */
    public function pelaku(): string
    {
        return $this->user?->fullname ?? '—';
    }

    /**
     * Alamat IP untuk ditampilkan. Aksi dari perintah konsol tidak punya asal
     * jaringan, dan itu memang wajar.
     */
    public function alamat(): string
    {
        return $this->ip_address ?? 'Sistem';
    }

    /**
     * Tautan menuju dokumen yang dikenai aksi ini, bila masih ada.
     *
     * Sengaja bergantung pada relasi `subject` yang sudah ter-resolve, bukan
     * pada `subject_id` mentah: baris beraksi `hapus` menunjuk dokumen yang
     * sudah tiada, dan menautkannya hanya akan mengantar pemeriksa ke 404.
     */
    public function tautanSubjek(): ?string
    {
        if ($this->subject === null) {
            return null;
        }

        return match ($this->subject_type) {
            'barang' => route('items.show', $this->subject),
            // `users` sengaja tidak punya rute show; halaman ubah adalah satu
            // satunya layar detail akun yang tersedia.
            'pengguna' => route('users.edit', $this->subject),
            'penyedia' => route('suppliers.show', $this->subject),
            'usulan_barang' => route('item-proposals.show', $this->subject),
            'usulan_pengadaan' => route('procurement-requests.show', $this->subject),
            'pengadaan' => route('procurements.show', $this->subject),
            'opname' => route('opname.show', $this->subject),
            // Berkas tidak punya layar sendiri: daftarnya menempel pada
            // halaman realisasi yang memilikinya.
            'dokumen_pengadaan' => route('procurements.show', $this->subject->procurement_id),
            default => null,
        };
    }

    /**
     * Penyaring bersama yang dipakai layar log dan ekspor CSV, supaya isi
     * berkas ekspor selalu identik dengan yang dilihat pengguna.
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                filled($filters['q'] ?? null),
                fn (Builder $query) => $query->where(
                    fn (Builder $cari) => $cari
                        ->where('activity', 'like', '%'.$filters['q'].'%')
                        // Nama pelaku dicari lewat relasi, bukan lewat scope
                        // baru di User: tabel dan model itu milik bersama dan
                        // tidak perlu disentuh untuk fitur ini.
                        ->orWhereHas(
                            'user',
                            fn (Builder $user) => $user
                                ->where('fullname', 'like', '%'.$filters['q'].'%')
                                ->orWhere('username', 'like', '%'.$filters['q'].'%'),
                        ),
                ),
            )
            ->when(
                filled($filters['user'] ?? null),
                fn (Builder $query) => $query->where('user_id', $filters['user']),
            )
            ->when(
                // Memakai cuplikan peran pada baris log, bukan peran pengguna
                // saat ini. Lihat ADR 0034.
                UserRole::tryFrom((string) ($filters['role'] ?? '')),
                fn (Builder $query, UserRole $role) => $query->where('actor_role', $role),
            )
            ->when(
                AuditModule::tryFrom((string) ($filters['module'] ?? '')),
                fn (Builder $query, AuditModule $module) => $query->where('module', $module),
            )
            ->when(
                AuditEvent::tryFrom((string) ($filters['event'] ?? '')),
                fn (Builder $query, AuditEvent $event) => $query->where('event', $event),
            )
            ->when(
                filled($filters['from'] ?? null),
                fn (Builder $query) => $query->whereDate('timestamp', '>=', $filters['from']),
            )
            ->when(
                filled($filters['to'] ?? null),
                fn (Builder $query) => $query->whereDate('timestamp', '<=', $filters['to']),
            );
    }

    /**
     * Dokumen yang dikenai aksi. Alias jenisnya terdaftar pada morph map di
     * AppServiceProvider. Bernilai null untuk aksi yang memang tidak menyentuh
     * dokumen tertentu, seperti masuk dan keluar sistem.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
