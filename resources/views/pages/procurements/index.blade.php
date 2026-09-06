@extends('layouts.app')

@section('content')
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Realisasi Pengadaan</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Pengadaan yang direalisasikan Pejabat Pengadaan dari usulan yang sudah disetujui, menunggu
            konfirmasi penerimaan Pengelola Gudang.
        </p>
    </div>

    <x-ui.flash />

    {{-- Ringkasan --}}
    <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-3 md:gap-6">
        @foreach ([
            ['label' => 'Total Realisasi', 'value' => $summary['total']],
            ['label' => 'Menunggu Penerimaan', 'value' => $summary['menunggu']],
            ['label' => 'Sudah Diterima', 'value' => $summary['diterima']],
        ] as $card)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                <h4 class="text-title-sm mt-2 font-bold text-gray-800 dark:text-white/90">{{ $card['value'] }}</h4>
            </div>
        @endforeach
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        {{-- Penyaring --}}
        <form method="GET" action="{{ route('procurements.index') }}"
            class="grid grid-cols-1 gap-4 border-b border-gray-100 p-5 sm:grid-cols-3 dark:border-gray-800 sm:p-6">
            <x-form.select-field name="status" label="Status Realisasi"
                :options="\App\Enums\ProcurementStatus::options()" :selected="$filters['status']"
                placeholder="Semua status" />

            <div class="flex items-end gap-3">
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                    Terapkan
                </button>
                @if ($filters['status'] !== '')
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
