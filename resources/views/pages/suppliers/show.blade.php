@extends('layouts.app')

@section('content')
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">{{ $supplier->supplier_name }}</h2>
            <nav class="mt-1.5">
                <ol class="flex items-center gap-1.5">
                    <li>
                        <a href="{{ route('suppliers.index') }}"
                            class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                            Penyedia Barang
                            <svg class="stroke-current" width="17" height="16" viewBox="0 0 17 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M6.0765 12.667L10.2432 8.50033L6.0765 4.33366" stroke="" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                    </li>
                    <li class="text-sm text-gray-800 dark:text-white/90">Detail</li>
                </ol>
            </nav>
        </div>

        <a href="{{ route('suppliers.edit', $supplier) }}"
            class="text-theme-sm rounded-lg px-4 py-2.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
            Ubah Penyedia
        </a>
    </div>

    <x-ui.flash />

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Identitas --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 lg:col-span-1 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-theme-lg font-semibold text-gray-800 dark:text-white/90">Identitas</h3>
                <span @class([
                    'text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium',
                    'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500' => $supplier->is_active,
                    'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400' => !$supplier->is_active,
                ])>
                    {{ $supplier->is_active ? 'Aktif' : 'Diarsipkan' }}
                </span>
            </div>

            <dl class="space-y-4">
                @foreach ([
                    ['Alamat', $supplier->address],
                    ['Narahubung', $supplier->contact_person],
                    ['Telepon', $supplier->phone],
                    ['Surel', $supplier->email],
                    ['NPWP', $supplier->npwp],
                ] as [$label, $value])
                    <div>
                        <dt class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="text-theme-sm mt-0.5 text-gray-800 dark:text-white/90">{{ $value ?: '—' }}</dd>
                    </div>
                @endforeach
            </dl>

            <p class="text-theme-xs mt-6 border-t border-gray-100 pt-4 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                Alamat dan narahubung dicantumkan pada BAST pengadaan, sehingga tidak perlu diketik ulang tiap dokumen.
            </p>
        </div>

        {{-- Riwayat pengadaan --}}
        <div class="rounded-2xl border border-gray-200 bg-white lg:col-span-2 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="border-b border-gray-100 p-5 dark:border-gray-800 sm:p-6">
                <h3 class="text-theme-lg font-semibold text-gray-800 dark:text-white/90">Riwayat Pengadaan</h3>
                <p class="text-theme-sm mt-1 text-gray-500 dark:text-gray-400">
                    {{ $supplier->procurements_count }} pengadaan berjalan kepada penyedia ini.
                    @if ($supplier->procurements_cancelled_count > 0)
                        {{ $supplier->procurements_cancelled_count }} lainnya dibatalkan sebelum barang diterima,
                        dan tetap ditampilkan di bawah tanpa ikut dihitung.
                    @endif
                    @if ($supplier->procurements_rejected_count > 0)
                        {{ $supplier->procurements_rejected_count }} ditolak Kasubbag Umum dan TI dan belum
                        diperbaiki, jadi belum ikut dihitung juga.
                    @endif
                </p>
            </div>

            <div class="custom-scrollbar max-w-full overflow-x-auto">
                <table class="w-full min-w-[520px]">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            @foreach (['Tanggal', 'Status', 'Jumlah Barang'] as $heading)
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
                                    <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                        {{ $row->procurement_date->translatedFormat('d F Y') }}
                                    </p>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <span class="text-theme-xs rounded-full px-2.5 py-1 font-medium {{ $row->status->badgeClass() }}">
                                        {{ $row->status->label() }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                        {{ $row->details()->count() }} barang
                                    </p>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-5 py-10 text-center sm:px-6">
                                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                        Belum ada pengadaan kepada penyedia ini.
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $procurements->links('vendor.pagination.tailadmin') }}
        </div>
    </div>
@endsection
