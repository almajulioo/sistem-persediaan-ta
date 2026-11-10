@extends('layouts.app')

@php
    $ringkasan = $opname->ringkasan();
    $adaYangBerubah = $opname->details->contains(fn ($d) => $d->stokBerubahSejakDicacah());

    // Barang kosong baru mengikuti stok sistem setelah opname insidental
    // diposting; selain itu ia belum diisi (ADR 0056, 0057).
    $labelTidakDiisi = $opname->kosongMengikutiStokSistem() ? 'Ikut Stok Sistem' : 'Belum Diisi';

    $penyaring = [
        'semua' => ['Semua', $ringkasan['barang']],
        'diisi' => ['Diisi Petugas', $ringkasan['diisi']],
        'berselisih' => ['Berselisih', $ringkasan['selisih']],
        'tidak_diisi' => [$labelTidakDiisi, $ringkasan['tidak_diisi']],
    ];
@endphp

@section('content')
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">{{ $opname->judul() }}</h2>
            <nav class="mt-1.5">
                <ol class="flex items-center gap-1.5">
                    <li>
                        <a href="{{ route('opname.index') }}"
                            class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                            Stok Opname
                            <svg class="stroke-current" width="17" height="16" viewBox="0 0 17 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M6.0765 12.667L10.2432 8.50033L6.0765 4.33366" stroke="" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                    </li>
                    <li class="text-sm text-gray-800 dark:text-white/90">
                        {{ $opname->period_type->label() }} {{ $opname->period_year }}
                    </li>
                </ol>
            </nav>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="text-theme-xs inline-flex items-center rounded-full px-2.5 py-1 font-medium {{ $opname->status->badgeClass() }}">
                {{ $opname->status->label() }}
            </span>

            @if ($opname->isSelesai())
                <a href="{{ route('opname.excel', $opname) }}"
                    class="text-theme-sm inline-flex items-center gap-2 rounded-lg px-4 py-3 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    <x-ui.icon name="download" class="size-4" />
                    Unduh Excel
                </a>
            @else
                @can('kelola-gudang')
                    <div x-data="{ buang: false, terkirim: false }">
                        <button type="button" @click="buang = true"
                            class="text-theme-sm text-error-600 dark:text-error-500 ring-error-200 dark:ring-error-500/30 hover:bg-error-50 dark:hover:bg-error-500/10 rounded-lg px-4 py-3 font-medium ring-1 ring-inset transition">
                            Buang Draf
                        </button>

                        <div x-show="buang" x-cloak @keydown.escape.window="buang = false"
                            class="fixed inset-0 z-99999 flex items-center justify-center p-5"
                            role="dialog" aria-modal="true" aria-labelledby="judul-konfirmasi-buang">
                            <div @click="buang = false" class="fixed inset-0 bg-gray-900/50"></div>

                            <div @click.stop class="relative w-full max-w-md rounded-2xl bg-white p-6 dark:bg-gray-900">
                                <h3 id="judul-konfirmasi-buang" class="text-theme-xl font-semibold text-gray-800 dark:text-white/90">
                                    Buang draf {{ $opname->judul() }}?
                                </h3>

                                <p class="text-theme-sm mt-3 text-gray-500 dark:text-gray-400">
                                    Hasil cacah yang sudah diisi akan hilang dan tidak dapat dikembalikan.
                                </p>

                                <form method="POST" action="{{ route('opname.destroy', $opname) }}"
                                    @submit="terkirim = true" class="mt-6 flex justify-end gap-3">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" @click="buang = false"
                                        class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                        Batal
                                    </button>
                                    <button type="submit" x-bind:disabled="terkirim"
                                        class="bg-error-500 hover:bg-error-600 rounded-lg px-4 py-2.5 text-sm font-medium text-white transition disabled:cursor-not-allowed disabled:opacity-50">
                                        Ya, Buang
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <a href="{{ route('opname.edit', $opname) }}"
                        class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 rounded-lg px-4 py-3 text-sm font-medium text-white transition">
                        Lanjut Cacah
                    </a>
                @endcan
            @endif
        </div>
    </div>

    <x-ui.flash />

    @if ($adaYangBerubah)
        <div class="bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400 mb-6 rounded-lg px-4 py-3 text-sm">
            Stok sebagian barang berubah setelah dicacah. Baris terkait ditandai di bawah.
        </div>
    @endif

    {{-- Ringkasan --}}
    <div class="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-4 md:gap-6">
        @foreach ([
            ['label' => 'Diisi Petugas', 'value' => $ringkasan['diisi'] . ' / ' . $ringkasan['barang'], 'class' => 'text-gray-800 dark:text-white/90'],
            ['label' => $labelTidakDiisi, 'value' => $ringkasan['tidak_diisi'], 'class' => 'text-gray-800 dark:text-white/90'],
            ['label' => 'Barang Berselisih', 'value' => $ringkasan['selisih'], 'class' => $ringkasan['selisih'] > 0 ? 'text-warning-600 dark:text-orange-400' : 'text-gray-800 dark:text-white/90'],
            ['label' => 'Selisih Lebih / Kurang', 'value' => '+' . $ringkasan['lebih'] . ' / -' . $ringkasan['kurang'], 'class' => 'text-gray-800 dark:text-white/90'],
        ] as $card)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                <h4 class="text-title-sm mt-2 font-bold {{ $card['class'] }}">{{ $card['value'] }}</h4>
            </div>
        @endforeach
    </div>

    {{-- Identitas dokumen --}}
    <div class="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                'Periode' => $opname->period_type->label() . ' ' . $opname->period_year,
                'Tanggal Pencacahan' => $opname->opname_date->translatedFormat('d F Y'),
                'Petugas' => $opname->user?->fullname ?? '—',
                'Diposting' => $opname->posted_at?->translatedFormat('d F Y, H:i') ?? 'Belum diposting',
            ] as $label => $value)
                <div>
                    <dt class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                    <dd class="text-theme-sm mt-1 font-medium text-gray-800 dark:text-white/90">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        @if ($opname->notes)
            <p class="mt-4 border-t border-gray-100 pt-4 text-sm text-gray-500 dark:border-gray-800 dark:text-gray-400">
                {{ $opname->notes }}
            </p>
        @endif
    </div>

    {{-- Rincian --}}
    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-5 sm:px-6">
            <h3 class="text-base font-medium text-gray-800 dark:text-white/90">Rincian Pencacahan</h3>

            <div class="flex flex-wrap gap-2" role="group" aria-label="Saring barang">
                @foreach ($penyaring as $kunci => [$label, $jumlah])
                    <a href="{{ route('opname.show', $kunci === 'semua' ? $opname : [$opname, 'baris' => $kunci]) }}"
                        @if ($saring === $kunci) aria-current="true" @endif
                        @class([
                            'text-theme-sm inline-flex items-center gap-1.5 rounded-lg px-3 py-2 font-medium ring-1 ring-inset transition',
                            'bg-brand-500 text-white ring-brand-500' => $saring === $kunci,
                            'text-gray-700 ring-gray-300 hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]' => $saring !== $kunci,
                        ])>
                        {{ $label }}
                        <span @class([
                            'text-theme-xs rounded-full px-1.5 tabular-nums',
                            'bg-white/20' => $saring === $kunci,
                            'bg-gray-100 dark:bg-gray-800' => $saring !== $kunci,
                        ])>{{ number_format($jumlah, 0, ',', '.') }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        <div class="custom-scrollbar max-w-full overflow-x-auto border-t border-gray-100 dark:border-gray-800">
            <table class="w-full min-w-[1120px]">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        @foreach (['Kode', 'Nama Barang', 'Stok Sistem', 'Stok Fisik', 'Selisih', 'Penyesuaian', 'Alasan', 'Keterangan'] as $heading)
                            <th class="px-5 py-3 text-left sm:px-6">
                                <p class="text-theme-xs font-medium whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $heading }}</p>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rincian as $detail)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="px-5 py-4 sm:px-6">
                                <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ $detail->item->item_code }}
                                </span>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <a href="{{ route('items.show', $detail->item) }}"
                                    class="text-theme-sm text-gray-800 hover:text-brand-500 dark:text-white/90 dark:hover:text-brand-400">
                                    {{ $detail->item->item_name }}
                                </a>
                                @if ($detail->stokBerubahSejakDicacah())
                                    <span class="text-theme-xs bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400 mt-1 inline-block rounded-full px-2 py-0.5 font-medium"
                                        title="Stok saat posting {{ $detail->stock_at_posting }}, berbeda dari stok saat dicacah {{ $detail->stock_stored }}">
                                        stok berubah sejak dicacah
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <span class="text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ number_format($detail->stokSistem(), 0, ',', '.') }}
                                </span>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                @if ($detail->mengikutiStokSistem())
                                    <span class="text-theme-sm text-gray-500 dark:text-gray-400">
                                        {{ number_format($detail->stock_real, 0, ',', '.') }}
                                    </span>
                                    <span class="text-theme-xs bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-400 mt-1 block w-fit rounded-full px-2 py-0.5 font-medium whitespace-nowrap">
                                        Ikut sistem
                                    </span>
                                @elseif ($detail->sudahDicacah())
                                    <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                        {{ number_format($detail->stock_real, 0, ',', '.') }}
                                    </span>
                                @else
                                    <span class="text-theme-sm text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <span @class([
                                    'text-theme-sm font-semibold',
                                    'text-success-600 dark:text-success-500' => $detail->sudahDicacah() && $detail->difference > 0,
                                    'text-error-600 dark:text-error-500' => $detail->sudahDicacah() && $detail->difference < 0,
                                    'text-gray-500 dark:text-gray-400' => !$detail->sudahDicacah() || $detail->difference === 0,
                                ])>
                                    {{ $detail->signedDifference() }}
                                </span>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <span class="text-theme-sm text-gray-500 dark:text-gray-400">
                                    @if ($detail->adjustment_amount === null)
                                        —
                                    @elseif ($detail->adjustment_amount === 0)
                                        Tidak ada
                                    @else
                                        {{ $detail->adjustment_amount > 0 ? '+' : '' }}{{ $detail->adjustment_amount }}
                                    @endif
                                </span>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                @if ($detail->adjustment_reason)
                                    <span class="text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium {{ $detail->adjustment_reason->badgeClass() }}">
                                        {{ $detail->adjustment_reason->label() }}
                                    </span>
                                @else
                                    <span class="text-theme-sm text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $detail->adjustment_note ?? '—' }}</p>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-10 text-center sm:px-6">
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">Tidak ada barang pada penyaring ini.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($opname->isSelesai())
            <div class="border-t border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
                <a href="{{ route('mutations.index', ['type' => \App\Enums\MutationType::Penyesuaian->value]) }}"
                    class="text-theme-sm text-brand-500 hover:text-brand-600 dark:text-brand-400">
                    Lihat mutasi penyesuaian &rarr;
                </a>
            </div>
        @endif
    </div>
@endsection
