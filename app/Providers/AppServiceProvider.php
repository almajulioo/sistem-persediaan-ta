<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\Procurement;
// Model permintaan barang bernama Request, bentrok dengan Illuminate\Http\Request.
use App\Models\Request as PermintaanBarang;
use App\Models\StockOpname;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Sistem tertutup: paksa HTTPS di luar lingkungan pengembangan.
        if (! $this->app->environment('local', 'testing')) {
            URL::forceScheme('https');
        }

        // Kebijakan kata sandi minimum untuk seluruh aplikasi.
        Password::defaults(function () {
            $rule = Password::min(12)->letters()->mixedCase()->numbers()->symbols();

            // Pemeriksaan kebocoran memanggil layanan luar; hanya relevan di produksi.
            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        $this->registerMorphMap();
        $this->registerGates();
    }

    /**
     * Alias untuk relasi polimorfik `stock_mutations.reference_*`.
     *
     * Memakai morphMap (bukan enforceMorphMap) supaya relasi polimorfik yang
     * nanti dibuat pengembang sub sistem permintaan tidak wajib terdaftar di sini.
     */
    private function registerMorphMap(): void
    {
        Relation::morphMap([
            'pengadaan' => Procurement::class,
            'permintaan' => PermintaanBarang::class,
            'opname' => StockOpname::class,
        ]);
    }

    /**
     * Gate berbasis peran, dipakai lewat @can pada Blade maupun ->can() pada rute.
     */
    private function registerGates(): void
    {
        // Admin adalah peran tertinggi: lolos seluruh pemeriksaan otorisasi.
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);

        Gate::define('kelola-pengguna', fn (User $user) => $user->hasRole(UserRole::Admin));

        Gate::define('kelola-gudang', fn (User $user) => $user->hasRole(UserRole::PengelolaGudang));

        Gate::define('kelola-pengadaan', fn (User $user) => $user->hasRole(UserRole::PejabatPengadaan));

        Gate::define('kelola-permintaan', fn (User $user) => $user->hasRole(UserRole::PengelolaGudang));
    }
}
