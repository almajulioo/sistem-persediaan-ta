@extends('layouts.app')

@php
    $tabUrl = fn (string $key) => route('item-applications.index', array_filter(
        ['q' => $filters['q'], 'status' => $key],
        fn ($value) => $value !== '',
    ));
    $canDecide = auth()->user()->can('kelola-permintaan');
@endphp

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Permohonan</h2>
            <p class="mt-1 max-w-2xl text-sm text-gray-500 dark:text-gray-400">
                Permohonan barang baru dan tambah stok. Setelah disetujui Pengelola Gudang, barang tambah stok masuk ke
                draf usulan pengadaan dan barang baru menjadi usulan barang baru.
            </p>
        </div>

        <a href="{{ route('item-applications.create') }}"
            class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium text-white transition">
            <x-ui.icon name="plus" class="size-5" />
            Ajukan Permohonan
        </a>
    </div>

    <x-ui.flash />

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-3 dark:border-gray-800 sm:px-4">
            <nav class="custom-scrollbar flex gap-1 overflow-x-auto" aria-label="Status permohonan">
                @foreach ($tabs as $key => $tab)
                    @php $aktif = $filters['status'] === (string) $key; @endphp
                    <a href="{{ $tabUrl((string) $key) }}" @if ($aktif) aria-current="page" @endif
                        @class([
                            'text-theme-sm -mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-3 py-3.5 font-medium transition',
                            'border-brand-500 text-brand-600 dark:text-brand-400' => $aktif,
                            'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300' => ! $aktif,
                        ])>
                        {{ $tab['label'] }}
                        <span @class([
                            'text-theme-xs rounded-full px-2 py-0.5',
                            'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400' => $aktif,
                            'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400' => ! $aktif,
                        ])>{{ $tab['jumlah'] }}</span>
                    </a>
                @endforeach
            </nav>

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
                    <a href="{{ route('item-applications.create') }}"
                        class="bg-brand-500 hover:bg-brand-600 mt-5 inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
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
                            <tr class="align-top transition hover:bg-gray-50/60 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4">
                                    <a href="{{ route('item-applications.show', $application) }}" class="text-theme-sm font-semibold text-gray-800 hover:text-brand-600 dark:text-white/90 dark:hover:text-brand-400">
                                        {{ $application->application_number }}
                                    </a>
                                    <p class="text-theme-xs mt-0.5 line-clamp-1 max-w-[14rem] text-gray-500 dark:text-gray-400">{{ $application->purpose }}</p>
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
                                <td class="max-w-[16rem] px-5 py-4">
                                    <p class="text-theme-sm truncate text-gray-700 dark:text-gray-300">{{ $application->ringkasanBarang() }}</p>
                                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ ucfirst($application->ringkasan()) }}</p>
                                </td>
                                <td class="text-theme-sm px-5 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400">
                                    {{ $application->date?->translatedFormat('d M Y') ?? '-' }}
                                </td>
                                <td class="px-5 py-4">
                                    <span class="text-theme-xs inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-medium whitespace-nowrap {{ $application->status->badgeClass() }}">
                                        <span class="size-1.5 rounded-full bg-current"></span>
                                        {{ $application->status->label() }}
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    @if ($application->isDiajukan() && $canDecide)
                                        <a href="{{ route('item-applications.show', $application) }}"
                                            class="text-theme-xs bg-brand-500 hover:bg-brand-600 rounded-lg px-3 py-1.5 font-medium text-white transition">
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
                    <li>
                        <a href="{{ route('item-applications.show', $application) }}" class="block px-5 py-4 transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-theme-sm font-semibold text-gray-800 dark:text-white/90">{{ $application->application_number }}</p>
                                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $application->user?->fullname ?? '-' }} · {{ $application->date?->translatedFormat('d M Y') ?? '-' }}
                                    </p>
                                </div>
                                <span class="text-theme-xs inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 font-medium {{ $application->status->badgeClass() }}">
                                    <span class="size-1.5 rounded-full bg-current"></span>
                                    {{ $application->status->label() }}
                                </span>
                            </div>
                            <p class="text-theme-sm mt-2 truncate text-gray-700 dark:text-gray-300">{{ $application->ringkasanBarang() }}</p>
                            <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ ucfirst($application->ringkasan()) }}</p>
                        </a>
                    </li>
                @endforeach
            </ul>

            {{ $applications->links('vendor.pagination.tailadmin') }}
        @endif
    </div>
@endsection
