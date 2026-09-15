@extends('layouts.app')

@section('content')
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Realisasi Pengadaan</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Pengadaan yang direalisasikan Pejabat Pengadaan, dari usulan yang sudah disetujui maupun
                langsung tanpa usulan, menunggu persetujuan Kasubbag Umum dan TI lalu konfirmasi penerimaan
                Pengelola Gudang.
            </p>
        </div>

        {{-- Kedua tautan cetak membawa penyaring yang sedang aktif, supaya isi
             laporan selalu sama persis dengan yang terlihat di layar. --}}
        <div class="flex flex-wrap items-center gap-3">
            @can('kelola-pengadaan')
                <a href="{{ route('procurements.create') }}"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium text-white transition">
                    <svg class="size-4 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path d="M10 3.75a.75.75 0 01.75.75v4.75h4.75a.75.75 0 010 1.5H10.75v4.75a.75.75 0 01-1.5 0V10.75H4.5a.75.75 0 010-1.5h4.75V4.5a.75.75 0 01.75-.75z" />
                    </svg>
                    Buat Realisasi Langsung
                </a>
            @endcan

            <a href="{{ route('procurements.csv', request()->query()) }}"
                class="inline-flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                <svg class="size-4 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                    <path d="M10 2.5a.75.75 0 01.75.75v7.19l2.22-2.22a.75.75 0 111.06 1.06l-3.5 3.5a.75.75 0 01-1.06 0l-3.5-3.5a.75.75 0 111.06-1.06l2.22 2.22V3.25A.75.75 0 0110 2.5zM3.5 13.75a.75.75 0 01.75.75v1.25h11.5V14.5a.75.75 0 011.5 0v1.5a1.25 1.25 0 01-1.25 1.25H4.0A1.25 1.25 0 012.75 16v-1.5a.75.75 0 01.75-.75z" />
                </svg>
                Ekspor CSV
            </a>

            <a href="{{ route('procurements.pdf', request()->query()) }}" target="_blank" rel="noopener"
                class="inline-flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                <svg class="size-4 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                    <path d="M6 2.5A1.5 1.5 0 004.5 4v2h11V4A1.5 1.5 0 0014 2.5H6zM3 7.5A1.5 1.5 0 001.5 9v4A1.5 1.5 0 003 14.5h1v2A1.5 1.5 0 005.5 18h9a1.5 1.5 0 001.5-1.5v-2h1a1.5 1.5 0 001.5-1.5V9A1.5 1.5 0 0017 7.5H3zm2.5 6h9v3h-9v-3z" />
                </svg>
                Cetak PDF
            </a>
        </div>
    </div>

    <x-ui.flash />

    @php
        // Dirakit lebih dulu, bukan sebagai ternari bersarang di dalam larik
        // kartu: dua angka gagal berbagi satu baris catatan.
        $catatanGagal = collect([
            $summary['dibatalkan'] > 0 ? number_format($summary['dibatalkan'], 0, ',', '.').' dibatalkan' : null,
            $summary['ditolak'] > 0 ? number_format($summary['ditolak'], 0, ',', '.').' ditolak' : null,
        ])->filter()->implode(', ') ?: null;

        // Dua baris catatan terpisah, bukan satu yang digabung: "tanpa usulan"
        // adalah irisan dari angka besarnya, sedangkan "dibatalkan/ditolak"
        // justru yang tidak ikut dihitung — menyandingkannya dalam satu baris
        // akan membuat keduanya terbaca sebagai hal yang sejenis.
        $catatanTotal = collect([
            $summary['langsung'] > 0
                ? number_format($summary['langsung'], 0, ',', '.').' tanpa usulan'
                : null,
            $catatanGagal,
        ])->filter()->values()->all();
    @endphp

    {{-- Rekap mengikuti penyaring, bukan seluruh basis data: angka di kartu
         harus sama dengan angka di laporan yang dicetak dari halaman ini.
         Realisasi yang dibatalkan maupun ditolak tidak ikut dihitung — keduanya
         dokumen yang pernah ada tetapi tidak pernah menjadi transaksi — dan
         tampil sebagai angka kecil tersendiri supaya tidak ada yang
         disembunyikan (ADR 0026, ADR 0030). --}}
    <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-3 md:gap-6 xl:grid-cols-6">
        @foreach ([
            ['label' => 'Jumlah Realisasi', 'value' => number_format($summary['total'], 0, ',', '.'), 'class' => 'text-gray-800 dark:text-white/90', 'catatan' => $catatanTotal],
            ['label' => 'Menunggu Persetujuan', 'value' => number_format($summary['menunggu_persetujuan'], 0, ',', '.'), 'class' => 'text-purple-600 dark:text-purple-400', 'catatan' => []],
            ['label' => 'Menunggu Penerimaan', 'value' => number_format($summary['menunggu'], 0, ',', '.'), 'class' => 'text-warning-600 dark:text-orange-400', 'catatan' => []],
            ['label' => 'Sudah Diterima', 'value' => number_format($summary['diterima'], 0, ',', '.'), 'class' => 'text-success-600 dark:text-success-500', 'catatan' => []],
            ['label' => 'Nilai Dipesan', 'value' => 'Rp' . number_format($summary['nilai'], 0, ',', '.'), 'class' => 'text-gray-800 dark:text-white/90', 'catatan' => []],
            ['label' => 'Nilai Diterima', 'value' => 'Rp' . number_format($summary['nilai_diterima'], 0, ',', '.'), 'class' => 'text-success-600 dark:text-success-500', 'catatan' => []],
        ] as $card)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                <h4 class="text-title-sm mt-2 font-bold {{ $card['class'] }}">{{ $card['value'] }}</h4>
                @foreach ($card['catatan'] as $catatan)
                    <p class="text-theme-xs mt-1 text-gray-400 dark:text-gray-500">{{ $catatan }}</p>
                @endforeach
            </div>
        @endforeach
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        {{-- Penyaring --}}
        <form method="GET" action="{{ route('procurements.index') }}"
            class="grid grid-cols-1 gap-4 border-b border-gray-100 p-5 sm:grid-cols-2 xl:grid-cols-5 dark:border-gray-800 sm:p-6">
            <x-form.select-field name="status" label="Status Realisasi"
                :options="\App\Enums\ProcurementStatus::options()" :selected="$filters['status']"
                placeholder="Semua status" />

            {{-- Dasar pengadaan: langsung versus dari usulan (ADR 0031). --}}
            <x-form.select-field name="sumber" label="Dasar Pengadaan"
                :options="['usulan' => 'Dari usulan', 'langsung' => 'Langsung (tanpa usulan)']"
                :selected="$filters['sumber']" placeholder="Semua dasar" />

            <x-form.select-field name="supplier" label="Penyedia" :options="$suppliers"
                :selected="$filters['supplier']" placeholder="Semua penyedia" />

            <x-form.text-field name="from" label="Tanggal Awal" type="date" :value="$filters['from']" />

            <x-form.text-field name="to" label="Tanggal Akhir" type="date" :value="$filters['to']" />

            <div class="flex items-center gap-3 sm:col-span-2 xl:col-span-5">
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                    Terapkan
                </button>
                @if (collect($filters)->filter()->isNotEmpty())
                    <a href="{{ route('procurements.index') }}"
                        class="text-theme-sm inline-flex items-center rounded-lg px-4 py-2.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                        Reset
                    </a>
                @endif
            </div>
        </form>

        {{-- Tabel --}}
        <div class="custom-scrollbar max-w-full overflow-x-auto">
            <table class="w-full min-w-[900px]">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        @foreach (['Realisasi', 'Penyedia', 'Barang', 'Nilai', 'Status', 'Aksi'] as $heading)
                            <th class="px-5 py-3 text-left sm:px-6">
                                <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</p>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($procurements as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="px-5 py-4 sm:px-6">
                                <a href="{{ route('procurements.show', $row) }}"
                                    class="text-theme-sm hover:text-brand-500 dark:hover:text-brand-400 font-medium text-gray-800 dark:text-white/90">
                                    {{ $row->judul() }}
                                </a>
                                <p class="text-theme-xs mt-0.5 text-gray-500 dark:text-gray-400">
                                    {{ $row->procurement_date->translatedFormat('d M Y') }}
                                </p>

                                {{-- Penanda ringkas, bukan kolom tersendiri: tabelnya sudah
                                     enam kolom, dan pertanyaannya cuma ya/tidak (ADR 0031). --}}
                                @if ($row->tanpaUsulan())
                                    <span class="text-theme-xs mt-1 inline-block rounded-full bg-gray-100 px-2 py-0.5 font-medium text-gray-600 dark:bg-white/[0.06] dark:text-gray-400">
                                        Langsung
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $row->supplier?->supplier_name ?? '—' }}
                                </p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $row->details->count() }} barang
                                </p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                    Rp{{ number_format($row->nilaiTotal(), 0, ',', '.') }}
                                </p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <span class="text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium {{ $row->status->badgeClass() }}">
                                    {{ $row->status->label() }}
                                </span>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <a href="{{ route('procurements.show', $row) }}"
                                    class="text-theme-xs rounded-lg px-3 py-1.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                    Lihat
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center sm:px-6">
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                    Belum ada realisasi pengadaan yang cocok dengan penyaring saat ini.
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $procurements->links('vendor.pagination.tailadmin') }}
    </div>
@endsection
