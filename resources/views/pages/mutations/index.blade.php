@extends('layouts.app')

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Riwayat Mutasi</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Seluruh pergerakan stok barang persediaan beserta dokumen sumbernya,
                diurutkan menurut waktu pencatatan.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('mutations.csv', request()->query()) }}"
                class="inline-flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                <svg class="size-4 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                    <path d="M10 2.5a.75.75 0 01.75.75v7.19l2.22-2.22a.75.75 0 111.06 1.06l-3.5 3.5a.75.75 0 01-1.06 0l-3.5-3.5a.75.75 0 111.06-1.06l2.22 2.22V3.25A.75.75 0 0110 2.5zM3.5 13.75a.75.75 0 01.75.75v1.25h11.5V14.5a.75.75 0 011.5 0v1.5a1.25 1.25 0 01-1.25 1.25H4.0A1.25 1.25 0 012.75 16v-1.5a.75.75 0 01.75-.75z" />
                </svg>
                Ekspor CSV
            </a>

            <a href="{{ route('mutations.pdf', request()->query()) }}" target="_blank" rel="noopener"
                class="inline-flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                <svg class="size-4 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                    <path d="M6 2.5A1.5 1.5 0 004.5 4v2h11V4A1.5 1.5 0 0014 2.5H6zM3 7.5A1.5 1.5 0 001.5 9v4A1.5 1.5 0 003 14.5h1v2A1.5 1.5 0 005.5 18h9a1.5 1.5 0 001.5-1.5v-2h1a1.5 1.5 0 001.5-1.5V9A1.5 1.5 0 0017 7.5H3zm2.5 6h9v3h-9v-3z" />
                </svg>
                Cetak PDF
            </a>

            @can('kelola-gudang')
                <a href="{{ route('mutations.create') }}"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium text-white transition">
                    <svg class="size-5 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd" clip-rule="evenodd" d="M10 3.25a.75.75 0 01.75.75v5.25H16a.75.75 0 010 1.5h-5.25V16a.75.75 0 01-1.5 0v-5.25H4a.75.75 0 010-1.5h5.25V4a.75.75 0 01.75-.75z" />
                    </svg>
                    Catat Mutasi Manual
                </a>
            @endcan
        </div>
    </div>

    <x-ui.flash />

    {{-- Rekap periode --}}
    <div class="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-4 md:gap-6">
        @foreach ([
            ['label' => 'Total Masuk', 'value' => '+' . number_format($summary['masuk'], 0, ',', '.'), 'class' => 'text-success-600 dark:text-success-500'],
            ['label' => 'Total Keluar', 'value' => '-' . number_format($summary['keluar'], 0, ',', '.'), 'class' => 'text-error-600 dark:text-error-500'],
            ['label' => 'Netto', 'value' => ($summary['netto'] > 0 ? '+' : '') . number_format($summary['netto'], 0, ',', '.'), 'class' => 'text-gray-800 dark:text-white/90'],
            ['label' => 'Jumlah Transaksi', 'value' => number_format($summary['transaksi'], 0, ',', '.'), 'class' => 'text-gray-800 dark:text-white/90'],
        ] as $card)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                <h4 class="text-title-sm mt-2 font-bold {{ $card['class'] }}">{{ $card['value'] }}</h4>
            </div>
        @endforeach
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        {{-- Penyaring --}}
        <form method="GET" action="{{ route('mutations.index') }}"
            class="grid grid-cols-1 gap-4 border-b border-gray-100 p-5 sm:grid-cols-2 xl:grid-cols-4 dark:border-gray-800 sm:p-6">
            <x-form.text-field name="q" label="Cari Barang" :value="$filters['q']"
                placeholder="Kode atau nama barang" />

            <x-form.select-field name="type" label="Jenis Mutasi" :options="\App\Enums\MutationType::options()"
                :selected="$filters['type']" placeholder="Semua jenis" />

            <x-form.text-field name="from" label="Tanggal Awal" type="date" :value="$filters['from']" />

            <x-form.text-field name="to" label="Tanggal Akhir" type="date" :value="$filters['to']" />

            <div class="flex items-center gap-3 sm:col-span-2 xl:col-span-4">
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                    Terapkan
                </button>
                @if (collect($filters)->filter()->isNotEmpty())
                    <a href="{{ route('mutations.index') }}"
                        class="text-theme-sm inline-flex items-center rounded-lg px-4 py-2.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                        Reset
                    </a>
                @endif
            </div>
        </form>

        {{-- Tabel --}}
        <div class="custom-scrollbar max-w-full overflow-x-auto">
            <table class="w-full min-w-[1220px]">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        @foreach ([
                            ['label' => 'Kode Barang', 'hint' => null],
                            ['label' => 'Nama Barang', 'hint' => null],
                            ['label' => 'Jenis Mutasi', 'hint' => null],
                            ['label' => 'Sumber', 'hint' => 'Otomatis dari dokumen, atau dicatat manual'],
                            ['label' => 'Jumlah', 'hint' => null],
                            ['label' => 'Stok Setelah', 'hint' => 'Sisa stok pada saat mutasi ini dicatat'],
                            ['label' => 'Tanggal', 'hint' => 'Tanggal transaksi, dapat berbeda dari waktu pencatatan'],
                            ['label' => 'Referensi', 'hint' => null],
                            ['label' => 'Petugas', 'hint' => 'Orang yang mengeksekusi transaksi ini'],
                        ] as $heading)
                            <th class="px-5 py-3 text-left sm:px-6">
                                <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400"
                                    @if ($heading['hint']) title="{{ $heading['hint'] }}" @endif>
                                    {{ $heading['label'] }}
                                </p>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($mutations as $mutation)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="px-5 py-4 sm:px-6">
                                <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ $mutation->item->item_code }}
                                </span>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <a href="{{ route('items.show', $mutation->item) }}"
                                    class="text-theme-sm text-gray-800 hover:text-brand-500 dark:text-white/90 dark:hover:text-brand-400">
                                    {{ $mutation->item->item_name }}
                                </a>
                                @if ($mutation->notes)
                                    <p class="text-theme-xs mt-0.5 text-gray-500 dark:text-gray-400">{{ $mutation->notes }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <span class="text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium {{ $mutation->mutation_type->badgeClass() }}">
                                    {{ $mutation->mutation_type->label() }}
                                </span>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <span class="text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium {{ $mutation->sumberBadgeClass() }}">
                                    {{ $mutation->sumber() }}
                                </span>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <span @class([
                                    'text-theme-sm font-semibold',
                                    'text-success-600 dark:text-success-500' => $mutation->amount > 0,
                                    'text-error-600 dark:text-error-500' => $mutation->amount < 0,
                                ])>
                                    {{ $mutation->signedAmount() }}
                                </span>
                                <span class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $mutation->item->unit->label() }}</span>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ number_format($mutation->stock_after, 0, ',', '.') }}
                                </p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $mutation->mutation_date->format('d/m/Y') }}
                                </p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $mutation->referensi() }}</p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $mutation->petugas() }}</p>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-5 py-10 text-center sm:px-6">
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                    Tidak ada mutasi yang cocok dengan penyaring saat ini.
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $mutations->links('vendor.pagination.tailadmin') }}
    </div>
@endsection
