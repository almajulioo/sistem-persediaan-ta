@extends('layouts.app')

@use('App\Enums\ModulPengajuan')
@use('App\Support\Warna')

@php
    $modul = ModulPengajuan::Permohonan;
    $tabNav = collect($tabs)->map(fn (array $tab, string $key) => [
        ...$tab,
        'url' => route('item-applications.index', array_filter(['q' => $filters['q'], 'status' => $key], fn ($value) => $value !== '')),
        'aktif' => $filters['status'] === $key,
    ])->values()->all();
    $canDecide = auth()->user()->can('kelola-permintaan');

    // Kotak ikon selalu berwarna Permohonan; ikonnya mengikuti isi: satu
    // jenis memakai ikon jenis itu, campuran keduanya ikon tumpukan.
    $ubinIsi = function ($application) {
        $jenis = collect($application->jumlahPerJenis());

        if ($jenis->count() === 1) {
            $satu = $jenis->first()['jenis'];

            return ['ikon' => $satu->icon(), 'judul' => 'Permohonan '.strtolower($satu->label())];
        }

        return ['ikon' => $jenis->isEmpty() ? 'clipboard-list' : 'layers', 'judul' => 'Permohonan tambah stok dan barang baru'];
    };
@endphp

@section('content')
    <x-modul.judul :modul="$modul"
        deskripsi="Setelah disetujui, tambah stok masuk ke draf usulan pengadaan dan barang baru menjadi usulan barang baru.">
        <a href="{{ $modul->ruteBuat() }}"
            class="shadow-theme-xs inline-flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium transition {{ $modul->tombolClass() }}">
            <x-ui.icon name="plus" class="size-5" />
            {{ $modul->labelBuat() }}
        </a>
    </x-modul.judul>

    <x-modul.catatan :modul="$modul" class="mb-6" />

    <x-ui.flash />

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3 md:gap-6">
        @foreach ([
            ['label' => 'Menunggu Ditinjau', 'value' => $summary['menunggu'], 'icon' => 'clock', 'warna' => 'warning'],
            ['label' => 'Diajukan Bulan Ini', 'value' => $summary['bulan_ini'], 'icon' => 'clipboard-list', 'warna' => $modul->warna()],
            ['label' => 'Disetujui Bulan Ini', 'value' => $summary['disetujui_bulan_ini'], 'icon' => 'check-circle', 'warna' => 'success'],
        ] as $card)
            <div class="flex items-center gap-4 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl {{ Warna::lembut($card['warna']) }}">
                    <x-ui.icon :name="$card['icon']" class="size-5" />
                </span>
                <div>
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                    <p class="text-title-sm font-bold text-gray-800 dark:text-white/90">{{ $card['value'] }}</p>
                </div>
            </div>
        @endforeach
    </div>

    <div class="rounded-2xl border border-t-[3px] border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03] {{ $modul->aksenClass() }}">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-3 dark:border-gray-800 sm:px-4">
            @include('pages.request._tabs', ['tabs' => $tabNav, 'ariaLabel' => 'Status permohonan'])

            <form method="GET" action="{{ route('item-applications.index') }}" class="flex w-full items-center gap-2 py-3 sm:w-auto">
                @if ($filters['status'] !== '')
                    <input type="hidden" name="status" value="{{ $filters['status'] }}">
                @endif
                <label class="relative block w-full sm:w-72">
                    <span class="sr-only">Cari permohonan</span>
                    <x-ui.icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-gray-400" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Nomor atau nama pemohon"
                        class="shadow-theme-xs h-10 w-full rounded-lg border border-gray-300 bg-transparent pr-3 pl-9 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 focus:outline-hidden dark:border-gray-700 dark:text-white/90">
                </label>
            </form>
        </div>

        {{-- Keterangan warna jenis barang yang dimohon. --}}
        <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-5 py-3 dark:border-gray-800">
            <span class="text-theme-xs mr-1 text-gray-500 dark:text-gray-400">Jenis barang:</span>
            @foreach (\App\Enums\ItemApplicationType::cases() as $jenis)
                <x-ui.pill :warna="$jenis->warna()" :ikon="$jenis->icon()">{{ $jenis->label() }}</x-ui.pill>
            @endforeach
        </div>

        @if ($applications->isEmpty())
            <div class="flex flex-col items-center px-5 py-14 text-center">
                <span class="flex size-14 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-white/5">
                    <x-ui.icon name="clipboard-list" class="size-7" />
                </span>
                @php $adaFilter = $filters['q'] !== '' || $filters['status'] !== ''; @endphp
                <p class="mt-4 text-sm font-medium text-gray-800 dark:text-white/90">
                    {{ $adaFilter ? 'Tidak ada permohonan yang cocok.' : 'Belum ada permohonan.' }}
                </p>
                <p class="text-theme-sm mt-1 text-gray-500 dark:text-gray-400">
                    {{ $adaFilter ? 'Coba ubah tab atau kata pencarian.' : 'Mohon barang baru atau tambah stok lewat tombol di bawah.' }}
                </p>
                @unless ($adaFilter)
                    <a href="{{ $modul->ruteBuat() }}"
                        class="mt-5 inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition {{ $modul->tombolClass() }}">
                        <x-ui.icon name="plus" class="size-4" />
                        Ajukan Permohonan
                    </a>
                @endunless
            </div>
        @else
            {{-- Layar lebar: tabel. --}}
            <div class="custom-scrollbar hidden max-w-full overflow-x-auto lg:block">
                <table class="w-full min-w-[900px]">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50/70 dark:border-gray-800 dark:bg-white/[0.02]">
                            @foreach (['Permohonan', 'Pemohon', 'Barang', 'Tanggal', 'Status', 'Aksi'] as $heading)
                                <th class="px-5 py-3 text-left">
                                    <p class="text-theme-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">{{ $heading }}</p>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($applications as $application)
                            @php $ubin = $ubinIsi($application); @endphp
                            <tr class="align-top transition hover:bg-gray-50/60 dark:hover:bg-white/[0.02]">
                                {{-- Garis kiri berwarna status; kotak ikon menandai isinya. --}}
                                <td class="border-l-[3px] px-5 py-4 {{ Warna::garis($application->status->warna()) }}">
                                    <div class="flex items-start gap-3">
                                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg {{ Warna::lembut($modul->warna()) }}" title="{{ $ubin['judul'] }}">
                                            <x-ui.icon :name="$ubin['ikon']" class="size-[18px]" />
                                        </span>
                                        <div class="min-w-0">
                                            <a href="{{ route('item-applications.show', $application) }}" class="text-theme-sm font-semibold text-gray-800 hover:text-brand-600 dark:text-white/90 dark:hover:text-brand-400">
                                                {{ $application->application_number }}
                                            </a>
                                            <p class="text-theme-xs mt-0.5 line-clamp-1 max-w-[14rem] text-gray-500 dark:text-gray-400">{{ $application->purpose }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        @if ($application->user)
                                            <x-ui.user-avatar :user="$application->user" class="size-9 text-xs" />
                                        @endif
                                        <div class="min-w-0">
                                            <p class="text-theme-sm truncate font-medium text-gray-800 dark:text-white/90">{{ $application->user?->fullname ?? '-' }}</p>
                                            <p class="text-theme-xs truncate text-gray-500 dark:text-gray-400">{{ $application->user?->unit?->label() ?? '-' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="max-w-[18rem] px-5 py-4">
                                    <p class="text-theme-sm truncate text-gray-700 dark:text-gray-300">{{ $application->ringkasanBarang() }}</p>
                                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                                        @foreach ($application->jumlahPerJenis() as $baris)
                                            <x-ui.pill :warna="$baris['jenis']->warna()" :ikon="$baris['jenis']->icon()" class="whitespace-nowrap">
                                                {{ $baris['jumlah'] }} {{ $baris['jenis']->label() }}
                                            </x-ui.pill>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="text-theme-sm px-5 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400">
                                    {{ $application->date?->translatedFormat('d M Y') ?? '-' }}
                                </td>
                                <td class="px-5 py-4">
                                    <x-ui.pill :warna="$application->status->warna()" :ikon="$application->status->icon()" class="whitespace-nowrap">
                                        {{ $application->status->label() }}
                                    </x-ui.pill>
                                </td>
                                <td class="px-5 py-4">
                                    @if ($application->isDiajukan() && $canDecide)
                                        <a href="{{ route('item-applications.show', $application) }}"
                                            class="text-theme-xs rounded-lg px-3 py-1.5 font-medium transition {{ $modul->tombolClass() }}">
                                            Tinjau
                                        </a>
                                    @else
                                        <a href="{{ route('item-applications.show', $application) }}"
                                            class="text-theme-xs rounded-lg px-3 py-1.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                            Detail
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Layar sempit: kartu ringkas. --}}
            <ul class="divide-y divide-gray-100 lg:hidden dark:divide-gray-800">
                @foreach ($applications as $application)
                    @php $ubin = $ubinIsi($application); @endphp
                    <li>
                        <a href="{{ route('item-applications.show', $application) }}"
                            class="flex items-start gap-3 border-l-[3px] px-5 py-4 transition hover:bg-gray-50 dark:hover:bg-white/[0.02] {{ Warna::garis($application->status->warna()) }}">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg {{ Warna::lembut($modul->warna()) }}">
                                <x-ui.icon :name="$ubin['ikon']" class="size-[18px]" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-theme-sm font-semibold text-gray-800 dark:text-white/90">{{ $application->application_number }}</p>
                                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                            {{ $application->user?->fullname ?? '-' }} · {{ $application->date?->translatedFormat('d M Y') ?? '-' }}
                                        </p>
                                    </div>
                                    <x-ui.pill :warna="$application->status->warna()" :ikon="$application->status->icon()" class="shrink-0">
                                        {{ $application->status->label() }}
                                    </x-ui.pill>
                                </div>
                                <p class="text-theme-sm mt-2 truncate text-gray-700 dark:text-gray-300">{{ $application->ringkasanBarang() }}</p>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    @foreach ($application->jumlahPerJenis() as $baris)
                                        <x-ui.pill :warna="$baris['jenis']->warna()" :ikon="$baris['jenis']->icon()">
                                            {{ $baris['jumlah'] }} {{ $baris['jenis']->label() }}
                                        </x-ui.pill>
                                    @endforeach
                                </div>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>

            {{ $applications->links('vendor.pagination.tailadmin') }}
        @endif
    </div>
@endsection
