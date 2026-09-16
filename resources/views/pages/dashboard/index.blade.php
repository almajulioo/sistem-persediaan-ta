@extends('layouts.app')

@php
    use App\Enums\UserRole;

    $toneClasses = [
        'brand' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
        'success' => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
        'warning' => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
        'error' => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
    ];

    // Modul dan peran yang boleh mengaksesnya. Tautan menyusul saat modul dibangun.
    $modules = [
        [
            'name' => 'Permintaan Barang',
            'description' => 'Pengajuan, verifikasi, dan penyerahan barang persediaan ke unit kerja.',
            'roles' => [UserRole::Admin, UserRole::PengelolaGudang],
        ],
        [
            'name' => 'Pengadaan',
            'description' => 'Usulan kebutuhan, proses pengadaan, hingga penerimaan barang.',
            'roles' => [UserRole::Admin, UserRole::PejabatPengadaan],
        ],
        [
            'name' => 'Persetujuan Pengadaan',
            'description' => 'Menilai realisasi pengadaan yang diajukan Pejabat Pengadaan sebelum barang dipesan.',
            'roles' => [UserRole::Admin, UserRole::KasubbagUmumTi],
            'route' => 'procurements.index',
        ],
        [
            'name' => 'Manajemen Gudang',
            'description' => 'Stok, mutasi barang, dan stock opname persediaan.',
            'roles' => [UserRole::Admin, UserRole::PengelolaGudang],
            'route' => 'mutations.index',
        ],
        [
            'name' => 'Master Data Barang',
            'description' => 'Data induk barang beserta batas stok minimum dan maksimum.',
            'roles' => [UserRole::Admin, UserRole::PengelolaGudang],
            'route' => 'items.index',
        ],
        [
            'name' => 'Manajemen Pengguna',
            'description' => 'Pembuatan akun, pengaturan peran, dan penonaktifan pengguna.',
            'roles' => [UserRole::Admin],
            'route' => 'users.index',
        ],
        [
            'name' => 'Log Aktivitas',
            'description' => 'Jejak audit seluruh aktivitas pengguna di dalam sistem.',
            'roles' => [UserRole::Admin],
            'route' => 'activity-logs.index',
        ],
    ];

    $accessibleModules = array_values(array_filter(
        $modules,
        fn ($module) => $user->hasRole(...$module['roles']),
    ));
@endphp

@section('content')
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Dasbor</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Ringkasan sistem persediaan BPK Perwakilan Provinsi Lampung.
        </p>
    </div>

    {{-- Kartu sambutan --}}
    <div class="mb-6 rounded-2xl border border-gray-200 bg-white p-5 md:p-6 dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-center gap-4">
                <span class="bg-brand-500 flex size-14 shrink-0 items-center justify-center rounded-full text-lg font-semibold text-white">
                    {{ $user->initials() }}
                </span>
                <div>
                    <h3 class="text-theme-xl font-semibold text-gray-800 dark:text-white/90">
                        Selamat datang, {{ $user->fullname }}
                    </h3>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <span class="text-theme-xs inline-flex items-center rounded-full px-2.5 py-1 font-medium {{ $user->role->badgeClass() }}">
                            {{ $user->role->label() }}
                        </span>
                        <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                            {{ $user->unit }} &middot; {{ '@'.$user->username }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="text-theme-xs text-gray-500 dark:text-gray-400 sm:text-right">
                <p>Login terakhir</p>
                <p class="mt-1 font-medium text-gray-700 dark:text-gray-300">
                    {{ $user->last_login_at?->timezone(config('app.timezone'))->translatedFormat('d F Y, H:i') ?? 'Baru pertama kali' }}
                </p>
                @if ($user->last_login_ip)
                    <p class="mt-0.5">dari {{ $user->last_login_ip }}</p>
                @endif
            </div>
        </div>

        <p class="mt-4 border-t border-gray-100 pt-4 text-sm text-gray-500 dark:border-gray-800 dark:text-gray-400">
            {{ $user->role->description() }}
        </p>
    </div>

    {{-- Metrik ringkas --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4 md:gap-6">
        @foreach ($metrics as $metric)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ $metric['label'] }}</span>
                <div class="mt-3 flex items-end justify-between">
                    <h4 class="text-title-sm font-bold text-gray-800 dark:text-white/90">{{ $metric['value'] }}</h4>
                    @if ($metric['dummy'])
                        <span class="text-theme-xs rounded-full px-2.5 py-1 font-medium {{ $toneClasses[$metric['tone']] }}">
                            dummy
                        </span>
                    @endif
                </div>
                <p class="text-theme-xs mt-2 text-gray-500 dark:text-gray-400">{{ $metric['caption'] }}</p>
            </div>
        @endforeach
    </div>

    {{-- Modul yang dapat diakses --}}
    <div class="rounded-2xl border border-gray-200 bg-white p-5 md:p-6 dark:border-gray-800 dark:bg-white/[0.03]">
        <h3 class="text-theme-xl font-semibold text-gray-800 dark:text-white/90">
            Modul yang Dapat Anda Akses
        </h3>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Daftar berikut mengikuti peran <span class="font-medium">{{ $user->role->label() }}</span>.
        </p>

        <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
            @foreach ($accessibleModules as $module)
                @php
                    $ready = isset($module['route']);
                    $cardClass = 'block rounded-xl border border-gray-200 p-4 transition dark:border-gray-800';
                    $cardClass .= $ready
                        ? ' hover:border-brand-300 dark:hover:border-brand-800 dark:hover:bg-white/[0.03]'
                        : '';
                @endphp

                @if ($ready)
                    <a href="{{ route($module['route']) }}" class="{{ $cardClass }}">
                @else
                    <div class="{{ $cardClass }}">
                @endif

                <div class="flex items-start justify-between gap-3">
                    <h4 class="font-medium text-gray-800 dark:text-white/90">{{ $module['name'] }}</h4>
                    @if ($ready)
                        <span class="text-theme-xs bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500 shrink-0 rounded-full px-2.5 py-1 font-medium">
                            tersedia
                        </span>
                    @else
                        <span class="text-theme-xs shrink-0 rounded-full bg-gray-100 px-2.5 py-1 font-medium text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                            segera
                        </span>
                    @endif
                </div>
                <p class="text-theme-sm mt-1.5 text-gray-500 dark:text-gray-400">{{ $module['description'] }}</p>

                @if ($ready)
                    </a>
                @else
                    </div>
                @endif
            @endforeach
        </div>
    </div>

    <p class="mt-6 text-center text-theme-xs text-gray-400 dark:text-gray-500">
        Seluruh angka pada halaman ini masih berupa data contoh dan akan digantikan data nyata
        setelah modul persediaan tersedia.
    </p>
@endsection
