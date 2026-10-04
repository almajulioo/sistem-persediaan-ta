@extends('layouts.app')

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Usulan Pengadaan</h2>

        @can('kelola-gudang')
            <a href="{{ route('procurement-requests.create') }}"
                class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium text-white transition">
                <svg class="size-5 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M10 3.25a.75.75 0 01.75.75v5.25H16a.75.75 0 010 1.5h-5.25V16a.75.75 0 01-1.5 0v-5.25H4a.75.75 0 010-1.5h5.25V4a.75.75 0 01.75-.75z" />
                </svg>
                Ajukan Usulan
            </a>
        @endcan
    </div>

    <x-ui.flash />

    @can('kelola-gudang')
        @if ($draftBanner)
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-warning-200 bg-warning-50 p-4 dark:border-warning-500/30 dark:bg-warning-500/15">
                <p class="text-theme-sm text-warning-700 dark:text-warning-400">
                    Draf usulan otomatis:
                    <strong>{{ $draftBanner->details_count }} barang</strong> di bawah stok minimum.
                </p>
                <a href="{{ route('procurement-requests.draft.edit') }}"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                    Tinjau Draf
                </a>
            </div>
        @endif
    @endcan

    {{-- Ringkasan --}}
    <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-5 md:gap-6">
        @foreach ([
            ['label' => 'Total Usulan', 'value' => $summary['total']],
            ['label' => 'Menunggu Persetujuan', 'value' => $summary['diajukan']],
            ['label' => 'Disetujui, Menunggu Realisasi', 'value' => $summary['disetujui_menunggu_realisasi']],
            ['label' => 'Disetujui, Selesai', 'value' => $summary['disetujui_selesai']],
            ['label' => 'Ditolak', 'value' => $summary['ditolak']],
        ] as $card)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                <h4 class="text-title-sm mt-2 font-bold text-gray-800 dark:text-white/90">{{ $card['value'] }}</h4>
            </div>
        @endforeach
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        {{-- Penyaring --}}
        <form method="GET" action="{{ route('procurement-requests.index') }}"
            class="grid grid-cols-1 gap-4 border-b border-gray-100 p-5 sm:grid-cols-2 dark:border-gray-800 sm:p-6">
            <x-form.select-field name="status" label="Status Usulan"
                :options="\App\Enums\ProcurementRequestStatus::options()" :selected="$filters['status']"
                placeholder="Semua status" />

            <x-form.select-field name="source" label="Sumber Usulan"
                :options="\App\Enums\ProcurementRequestSource::options()" :selected="$filters['source']"
                placeholder="Semua sumber" />

            <div class="flex items-center gap-3 sm:col-span-2">
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                    Terapkan
                </button>
                @if ($filters['status'] !== '' || $filters['source'] !== '')
                    <a href="{{ route('procurement-requests.index') }}"
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
                        @foreach (['Usulan', 'Pengaju', 'Sumber', 'Barang', 'Status', 'Aksi'] as $heading)
                            <th class="px-5 py-3 text-left sm:px-6">
                                <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</p>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($usulan as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="px-5 py-4 sm:px-6">
                                <a href="{{ route('procurement-requests.show', $row) }}"
                                    class="text-theme-sm hover:text-brand-500 dark:hover:text-brand-400 font-medium text-gray-800 dark:text-white/90">
                                    {{ $row->judul() }}
                                </a>
                                <p class="text-theme-xs mt-0.5 text-gray-500 dark:text-gray-400">
                                    {{ $row->created_at->translatedFormat('d M Y, H:i') }}
                                </p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $row->user?->fullname ?? '—' }}
                                </p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <span class="text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium {{ $row->source->badgeClass() }}">
                                    {{ $row->source->label() }}
                                </span>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $row->details->count() }} barang
                                </p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <span class="text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium {{ $row->status->badgeClass() }}">
                                    {{ $row->status->label() }}
                                </span>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <a href="{{ route('procurement-requests.show', $row) }}"
                                    class="text-theme-xs rounded-lg px-3 py-1.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                    Lihat
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center sm:px-6">
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                    Belum ada usulan pengadaan yang cocok.
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $usulan->links('vendor.pagination.tailadmin') }}
    </div>
@endsection
