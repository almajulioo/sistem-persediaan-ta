@extends('layouts.app')

@php
    // Posisi stok saat ini pada rentang 0..(maks atau 1.5x min) untuk bar indikator.
    $ceiling = $item->stock_max > 0
        ? $item->stock_max
        : max($item->stock_min * 2, $item->stock_current, 1);
    $fill = min(100, (int) round($item->stock_current / max($ceiling, 1) * 100));
    $minMark = min(100, (int) round($item->stock_min / max($ceiling, 1) * 100));
@endphp

@section('content')
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">{{ $item->item_name }}</h2>
            <nav class="mt-1.5">
                <ol class="flex items-center gap-1.5">
                    <li>
                        <a href="{{ route('items.index') }}"
                            class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                            Master Barang
                            <svg class="stroke-current" width="17" height="16" viewBox="0 0 17 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M6.0765 12.667L10.2432 8.50033L6.0765 4.33366" stroke="" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                    </li>
                    <li class="text-sm text-gray-800 dark:text-white/90">{{ $item->item_code }}</li>
                </ol>
            </nav>
        </div>

        <a href="{{ route('items.edit', $item) }}"
            class="rounded-lg px-4 py-3 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
            Ubah Barang
        </a>
    </div>

    <x-ui.flash />

    <div class="grid grid-cols-12 gap-4 md:gap-6">
        {{-- Identitas & stok --}}
        <div class="col-span-12 xl:col-span-5">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">Stok saat ini</p>
                        <p class="mt-1 flex items-baseline gap-2">
                            <span class="text-title-sm font-bold text-gray-800 dark:text-white/90">
                                {{ number_format($item->stock_current, 0, ',', '.') }}
                            </span>
                            <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $item->unit->label() }}</span>
                        </p>
                    </div>
                    <span class="text-theme-xs shrink-0 rounded-full px-2.5 py-1 font-medium {{ $item->stockStatus()->badgeClass() }}">
                        {{ $item->stockStatus()->label() }}
                    </span>
                </div>

                {{-- Bar indikator stok --}}
                <div class="mt-5">
                    <div class="relative h-2.5 w-full rounded-full bg-gray-100 dark:bg-gray-800">
                        <div class="bg-brand-500 h-2.5 rounded-full" style="width: {{ $fill }}%"></div>
                        @if ($item->stock_min > 0)
                            <span class="bg-error-500 absolute top-[-3px] h-4 w-0.5" style="left: {{ $minMark }}%"
                                title="Stok minimum"></span>
                        @endif
                    </div>
                    <div class="text-theme-xs mt-2 flex justify-between text-gray-500 dark:text-gray-400">
                        <span>0</span>
                        <span>
                            Min {{ number_format($item->stock_min, 0, ',', '.') }}
                            &middot;
                            Maks {{ $item->stock_max > 0 ? number_format($item->stock_max, 0, ',', '.') : 'tidak dibatasi' }}
                        </span>
                    </div>
                </div>

                <dl class="mt-6 space-y-3 border-t border-gray-100 pt-5 dark:border-gray-800">
                    @foreach ([
                        'Kode Barang' => $item->item_code,
                        'Satuan' => $item->unit->label(),
                        'Status' => $item->is_active ? 'Aktif' : 'Diarsipkan',
                        'Didaftarkan' => $item->created_at?->translatedFormat('d F Y, H:i') ?? '-',
                        'Diperbarui' => $item->updated_at?->translatedFormat('d F Y, H:i') ?? '-',
                    ] as $label => $value)
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="text-theme-sm text-right font-medium text-gray-800 dark:text-white/90">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>

        {{-- Riwayat mutasi --}}
        <div class="col-span-12 xl:col-span-7">
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="px-5 py-5 sm:px-6">
                    <h3 class="text-base font-medium text-gray-800 dark:text-white/90">Riwayat Mutasi Stok</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Setiap perubahan stok tercatat di sini, dari saldo awal hingga transaksi terakhir.
                    </p>
                </div>

                <div class="custom-scrollbar max-w-full overflow-x-auto border-t border-gray-100 dark:border-gray-800">
                    <table class="w-full min-w-[520px]">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                @foreach (['Tanggal', 'Jenis', 'Jumlah', 'Referensi'] as $heading)
                                    <th class="px-5 py-3 text-left sm:px-6">
                                        <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</p>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($mutations as $mutation)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="px-5 py-4 sm:px-6">
                                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                            {{ $mutation->mutation_date->translatedFormat('d M Y') }}
                                        </p>
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <span class="text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium {{ $mutation->mutation_type->badgeClass() }}">
                                            {{ $mutation->mutation_type->label() }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <span @class([
                                            'text-theme-sm font-medium',
                                            'text-success-600 dark:text-success-500' => $mutation->amount > 0,
                                            'text-error-600 dark:text-error-500' => $mutation->amount < 0,
                                        ])>
                                            {{ $mutation->signedAmount() }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                            {{ $mutation->reference_type ?? '—' }}
                                        </p>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-5 py-10 text-center sm:px-6">
                                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                            Belum ada mutasi stok untuk barang ini.
                                        </p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{ $mutations->links('vendor.pagination.tailadmin') }}
            </div>
        </div>
    </div>
@endsection
