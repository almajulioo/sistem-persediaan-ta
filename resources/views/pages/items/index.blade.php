@extends('layouts.app')

@section('content')
    {{-- Kasubbag Umum dan TI membuka halaman ini baca-saja (ADR 0041). --}}
    @php $bisaKelola = auth()->user()->can('kelola-gudang'); @endphp

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Master Barang</h2>

        @if ($bisaKelola)
            <a href="{{ route('items.create') }}"
                class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium text-white transition">
                <svg class="size-5 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M10 3.25a.75.75 0 01.75.75v5.25H16a.75.75 0 010 1.5h-5.25V16a.75.75 0 01-1.5 0v-5.25H4a.75.75 0 010-1.5h5.25V4a.75.75 0 01.75-.75z" />
                </svg>
                Tambah Barang
            </a>
        @endif
    </div>

    <x-ui.flash />

    {{-- Ringkasan --}}
    <div class="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-4 md:gap-6">
        @foreach ([
            ['label' => 'Total Barang', 'value' => $summary['total'], 'tone' => null],
            ['label' => 'Barang Aktif', 'value' => $summary['aktif'], 'tone' => null],
            ['label' => 'Stok Menipis', 'value' => $summary['menipis'], 'tone' => \App\Enums\StockStatus::Menipis],
            ['label' => 'Stok Habis', 'value' => $summary['habis'], 'tone' => \App\Enums\StockStatus::Habis],
        ] as $card)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                <h4 @class([
                    'text-title-sm mt-2 font-bold',
                    'text-gray-800 dark:text-white/90' => $card['tone'] === null || $card['value'] === 0,
                    'text-warning-600 dark:text-orange-400' => $card['tone'] === \App\Enums\StockStatus::Menipis && $card['value'] > 0,
                    'text-error-600 dark:text-error-500' => $card['tone'] === \App\Enums\StockStatus::Habis && $card['value'] > 0,
                ])>{{ $card['value'] }}</h4>
            </div>
        @endforeach
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        {{-- Penyaring --}}
        <form method="GET" action="{{ route('items.index') }}"
            class="grid grid-cols-1 gap-4 border-b border-gray-100 p-5 sm:grid-cols-2 xl:grid-cols-4 dark:border-gray-800 sm:p-6">
            <x-form.text-field name="q" label="Cari" :value="$keyword" placeholder="Kode atau nama barang" />

            <x-form.select-field name="unit" label="Satuan" :options="\App\Enums\SatuanBarang::options()"
                :selected="$unit" placeholder="Semua satuan" />

            <x-form.select-field name="stock" label="Status Stok" :options="\App\Enums\StockStatus::options()"
                :selected="$stock" placeholder="Semua status stok" />

            <x-form.select-field name="status" label="Status Barang"
                :options="['aktif' => 'Aktif', 'arsip' => 'Diarsipkan']" :selected="$status"
                placeholder="Semua barang" />

            <div class="flex items-center gap-3 sm:col-span-2 xl:col-span-4">
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                    Terapkan
                </button>
                @if ($keyword !== '' || $unit !== '' || $stock !== '' || $status !== '')
                    <a href="{{ route('items.index') }}"
                        class="text-theme-sm inline-flex items-center rounded-lg px-4 py-2.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                        Reset
                    </a>
                @endif
            </div>
        </form>

        {{-- Tabel --}}
        <div x-data="{ confirmOpen: false, confirmAction: '', confirmName: '' }">
            <div class="custom-scrollbar max-w-full overflow-x-auto">
                <table class="w-full min-w-[980px]">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            @foreach (array_filter(['Kode', 'Nama Barang', 'Satuan', 'Stok Saat Ini', 'Min / Maks', 'Status', $bisaKelola ? 'Aksi' : null]) as $heading)
                                <th class="px-5 py-3 text-left sm:px-6">
                                    <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</p>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-5 py-4 sm:px-6">
                                    <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                        {{ $row->item_code }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <a href="{{ route('items.show', $row) }}"
                                        class="text-theme-sm font-medium text-gray-800 hover:text-brand-500 dark:text-white/90 dark:hover:text-brand-400">
                                        {{ $row->item_name }}
                                    </a>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $row->unit->label() }}</p>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <div class="flex items-center gap-2">
                                        <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                            {{ number_format($row->stock_current, 0, ',', '.') }}
                                        </span>
                                        <span class="text-theme-xs rounded-full px-2 py-0.5 font-medium {{ $row->stockStatus()->badgeClass() }}">
                                            {{ $row->stockStatus()->label() }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                        {{ number_format($row->stock_min, 0, ',', '.') }} /
                                        {{ $row->stock_max > 0 ? number_format($row->stock_max, 0, ',', '.') : '∞' }}
                                    </p>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <span @class([
                                        'text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium',
                                        'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500' => $row->is_active,
                                        'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400' => !$row->is_active,
                                    ])>
                                        {{ $row->is_active ? 'Aktif' : 'Diarsipkan' }}
                                    </span>
                                </td>
                                @if ($bisaKelola)
                                <td class="px-5 py-4 sm:px-6">
                                    <div class="flex items-center gap-2">
                                        <a href="{{ route('items.edit', $row) }}"
                                            class="text-theme-xs rounded-lg px-3 py-1.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                            Ubah
                                        </a>

                                        <form method="POST" action="{{ route('items.toggle-status', $row) }}">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit"
                                                class="text-theme-xs rounded-lg px-3 py-1.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                                {{ $row->is_active ? 'Arsipkan' : 'Aktifkan' }}
                                            </button>
                                        </form>

                                        <button type="button"
                                            @click="confirmOpen = true; confirmAction = @js(route('items.destroy', $row)); confirmName = @js($row->item_code.' — '.$row->item_name)"
                                            class="text-theme-xs text-error-600 dark:text-error-500 ring-error-200 dark:ring-error-500/30 hover:bg-error-50 dark:hover:bg-error-500/10 rounded-lg px-3 py-1.5 font-medium ring-1 ring-inset transition">
                                            Hapus
                                        </button>
                                    </div>
                                </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $bisaKelola ? 7 : 6 }}" class="px-5 py-10 text-center sm:px-6">
                                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                        Tidak ada barang yang cocok.
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $items->links('vendor.pagination.tailadmin') }}

            {{-- Konfirmasi hapus --}}
            <div x-show="confirmOpen" x-cloak @keydown.escape.window="confirmOpen = false"
                class="fixed inset-0 z-99999 flex items-center justify-center p-5">
                <div @click="confirmOpen = false" class="fixed inset-0 bg-gray-900/50"></div>

                <div @click.stop class="relative w-full max-w-md rounded-2xl bg-white p-6 dark:bg-gray-900">
                    <h3 class="text-theme-xl font-semibold text-gray-800 dark:text-white/90">Hapus barang?</h3>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        <span class="font-medium text-gray-700 dark:text-gray-300" x-text="confirmName"></span>
                        akan dihapus permanen.
                    </p>

                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" @click="confirmOpen = false"
                            class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                            Batal
                        </button>
                        <form method="POST" :action="confirmAction">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                class="bg-error-500 hover:bg-error-600 rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                                Ya, hapus
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
