<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'user_id';

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'username',
        'email',
        'password',
        'role',
        'unit',
        'fullname',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
            'role' => UserRole::class,
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Apakah pengguna memiliki salah satu dari peran yang diberikan.
     */
    public function hasRole(UserRole|string ...$roles): bool
    {
        foreach ($roles as $role) {
            $role = $role instanceof UserRole ? $role : UserRole::tryFrom($role);

            if ($role !== null && $this->role === $role) {
                return true;
            }
        }

        return false;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(UserRole::Admin);
    }

    /**
     * Inisial nama lengkap, untuk avatar teks pada UI.
     */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->fullname ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtoupper(mb_substr($parts[0] ?? 'U', 0, 1).mb_substr($parts[1] ?? '', 0, 1));
    }

    /**
     * Relasi transaksional yang memakai onDelete restrict. Selama masih ada
     * isinya, akun tidak boleh dihapus dan sebaiknya dinonaktifkan saja.
     *
     * @var list<string>
     */
    public const RESTRICTED_RELATIONS = [
        'auditLogs' => 'log aktivitas',
        'stockMutations' => 'riwayat mutasi stok',
        'stockOpnames' => 'stock opname',
        'procurements' => 'pengadaan',
        'procurementRequests' => 'usulan pengadaan',
    ];

    /**
     * Nama relasi transaksional yang masih memiliki data untuk akun ini.
     *
     * @return array<int, string>
     */
    public function blockingRelations(): array
    {
        $blocking = [];

        foreach (self::RESTRICTED_RELATIONS as $relation => $label) {
            if ($this->{$relation}()->exists()) {
                $blocking[] = $label;
            }
        }

        return $blocking;
    }

    public function requests(): HasMany
    {
        return $this->hasMany(Request::class, 'user_id', 'user_id');
    }

    public function stockMutations(): HasMany
    {
        return $this->hasMany(StockMutation::class, 'user_id', 'user_id');
    }

    public function stockOpnames(): HasMany
    {
        return $this->hasMany(StockOpname::class, 'user_id', 'user_id');
    }

    public function procurements(): HasMany
    {
        return $this->hasMany(Procurement::class, 'user_id', 'user_id');
    }

    public function procurementRequests(): HasMany
    {
        return $this->hasMany(ProcurementRequest::class, 'user_id', 'user_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'user_id', 'user_id');
    }
}
