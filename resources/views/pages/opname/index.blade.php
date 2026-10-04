@extends('layouts.app')

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Stok Opname</h2>

        @can('kelola-gudang')
            <a href="{{ route('opname.create') }}"
                class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium text-white transition">
                <svg class="size-5 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M10 3.25a.75.75 0 01.75.75v5.25H16a.75.75 0 010 1.5h-5.25V16a.75.75 0 01-1.5 0v-5.25H4a.75.75 0 010-1.5h5.25V4a.75.75 0 01.75-.75z" />
                </svg>
                Mulai Opname
            </a>
        @endcan
    </div>

    <x-ui.flash />

    {{-- Ringkasan --}}
    <div class="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-4 md:gap-6">
        @foreach ([
            ['label' => 'Total Opname', 'value' => $summary['total'], 'class' => 'text-gray-800 dark:text-white/90'],
            ['label' => 'Masih Draf', 'value' => $summary['draf'], 'class' => $summary['draf'] > 0 ? 'text-warning-600 dark:text-orange-400' : 'text-gray-800 dark:text-white/90'],
            ['label' => 'Selesai', 'value' => $summary['selesai'], 'class' => 'text-success-600 dark:text-success-500'],
            ['label' => 'Tahun ' . now()->year, 'value' => $summary['tahun_ini'], 'class' => 'text-gray-800 dark:text-white/90'],
        ] as $card)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                <h4 class="text-title-sm mt-2 font-bold {{ $card['class'] }}">{{ $card['value'] }}</h4>
            </div>
        @endforeach
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        {{-- Penyaring --}}
        <form method="GET" action="{{ route('opname.index') }}"
            class="grid grid-cols-1 gap-4 border-b border-gray-100 p-5 sm:grid-cols-2 xl:grid-cols-4 dark:border-gray-800 sm:p-6">
            <x-form.select-field name="period" label="Periode" :options="\App\Enums\PeriodeOpname::options()"
                :selected="$filters['period']" placeholder="Semua periode" />

            <x-form.text-field name="year" label="Tahun" type="number" :value="$filters['year']"
                min="2000" max="2100" placeholder="mis. {{ now()->year }}" />

            <x-form.select-field name="status" label="Status" :options="\App\Enums\OpnameStatus::options()"
                :selected="$filters['status']" placeholder="Semua status" />

            <div class="flex items-end gap-3">
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-11 items-center rounded-lg px-4 text-sm font-medium text-white transition">
                    Terapkan
                </button>
                @if (collect($filters)->filter()->isNotEmpty())
                    <a href="{{ route('opname.index') }}"
                        class="text-theme-sm inline-flex h-11 items-center rounded-lg px-4 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                        Reset
                    </a>
                @endif
            </div>
        </form>

        {{-- Tabel --}}
        <div class="custom-scrollbar max-w-full overflow-x-auto">
            <table class="w-full min-w-[980px]">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        @foreach (['Periode', 'Tanggal Cacah', 'Status', 'Pencacahan', 'Selisih', 'Petugas', 'Aksi'] as $heading)
                            <th class="px-5 py-3 text-left sm:px-6">
                                <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</p>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($opnames as $opname)
                        @php $ringkasan = $opname->ringkasan(); @endphp
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="px-5 py-4 sm:px-6">
                                <a href="{{ route('opname.show', $opname) }}"
                                    class="text-theme-sm font-medium text-gray-800 hover:text-brand-500 dark:text-white/90 dark:hover:text-brand-400">
                                    {{ $opname->period_type->label() }} {{ $opname->period_year }}
                                </a>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $opname->opname_date->translatedFormat('d M Y') }}
                                </p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <span class="text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium {{ $opname->status->badgeClass() }}">
                                    {{ $opname->status->label() }}
                                </span>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $ringkasan['dicacah'] }} / {{ $ringkasan['barang'] }} barang
                                </p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                @if ($ringkasan['selisih'] > 0)
                                    <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                        {{ $ringkasan['selisih'] }} barang
                                    </span>
                                    <span class="text-theme-xs block text-gray-500 dark:text-gray-400">
                                        <span class="text-success-600 dark:text-success-500">+{{ $ringkasan['lebih'] }}</span>
                                        /
                                        <span class="text-error-600 dark:text-error-500">-{{ $ringkasan['kurang'] }}</span>
                                    </span>
                                @else
                                    <span class="text-theme-sm text-gray-500 dark:text-gray-400">Tidak ada</span>
                                @endif
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $opname->user?->fullname ?? '—' }}</p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <div class="flex items-center gap-2">
                                    {{-- Draf hanya bisa dilanjutkan pencacahnya; Kasubbag
                                         melihatnya lewat "Lihat" (ADR 0041). --}}
                                    @if ($opname->isDraf() && auth()->user()->can('kelola-gudang'))
                                        <a href="{{ route('opname.edit', $opname) }}"
                                            class="text-theme-xs rounded-lg px-3 py-1.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                            Lanjut Cacah
                                        </a>
                                    @else
                                        <a href="{{ route('opname.show', $opname) }}"
                                            class="text-theme-xs rounded-lg px-3 py-1.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                            Lihat
                                        </a>
                                        @unless ($opname->isDraf())
                                            <a href="{{ route('opname.pdf', $opname) }}" target="_blank" rel="noopener"
                                                class="text-theme-xs rounded-lg px-3 py-1.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                                Cetak
                                            </a>
                                        @endunless
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-10 text-center sm:px-6">
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                    Belum ada stok opname yang cocok.
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $opnames->links('vendor.pagination.tailadmin') }}
    </div>
@endsection
