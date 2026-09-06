@extends('layouts.app')

@section('content')
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">{{ $procurementRequest->judul() }}</h2>
            <nav class="mt-1.5">
                <ol class="flex items-center gap-1.5">
                    <li>
                        <a href="{{ route('procurement-requests.index') }}"
                            class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                            Usulan Pengadaan
                            <svg class="stroke-current" width="17" height="16" viewBox="0 0 17 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M6.0765 12.667L10.2432 8.50033L6.0765 4.33366" stroke="" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                    </li>
                    <li class="text-sm text-gray-800 dark:text-white/90">{{ $procurementRequest->judul() }}</li>
                </ol>
            </nav>
        </div>

        <div class="flex items-center gap-3">
            <span class="text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium {{ $procurementRequest->status->badgeClass() }}">
                {{ $procurementRequest->status->label() }}
            </span>

            @if ($procurementRequest->isDisetujui() && ! $procurementRequest->procurement()->exists())
                @can('kelola-pengadaan')
                    <a href="{{ route('procurements.create', ['procurement_request_id' => $procurementRequest->procurement_request_id]) }}"
                        class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                        Realisasikan
                    </a>
                @endcan
            @elseif ($procurementRequest->procurement()->exists())
                <a href="{{ route('procurements.show', $procurementRequest->procurement) }}"
                    class="text-theme-sm rounded-lg px-4 py-2.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    Lihat Realisasi
                </a>
            @endif
        </div>
    </div>

    <x-ui.flash />

    {{-- Tanpa keterangan ini, usulan berstatus Disetujui yang tidak punya
         tombol "Realisasikan" terbaca seperti bug, padahal itu justru
         konsekuensi yang disengaja dari ADR 0024. --}}
    @if ($procurementRequest->procurement?->isDibatalkan())
        <div class="border-error-200 dark:border-error-500/30 bg-error-50 dark:bg-error-500/10 mb-6 rounded-2xl border p-5">
            <h3 class="text-theme-sm text-error-700 dark:text-error-400 font-medium">
                Realisasi usulan ini dibatalkan
            </h3>
            <p class="text-theme-sm text-error-600 dark:text-error-400/90 mt-1.5">
                {{ $procurementRequest->procurement->judul() }} dibatalkan dengan alasan
                {{ strtolower($procurementRequest->procurement->cancel_reason?->label() ?? 'yang tidak dicatat') }},
                sehingga barangnya tidak jadi datang. Usulan ini sudah selesai riwayatnya dan tidak dapat
                direalisasikan ulang &mdash; bila barangnya masih dibutuhkan, Pengelola Gudang mengajukan usulan
                pengadaan yang baru.
            </p>
        </div>
    @endif

    <div class="grid grid-cols-12 gap-4 md:gap-6">
        {{-- Identitas usulan --}}
        <div class="col-span-12 xl:col-span-4">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                <dl class="space-y-3">
                    @foreach ([
                        'Pengaju' => $procurementRequest->user?->fullname ?? '—',
                        'Sumber' => $procurementRequest->source->label(),
                        'Diajukan' => $procurementRequest->created_at->translatedFormat('d F Y, H:i'),
                    ] as $label => $value)
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="text-theme-sm text-right font-medium text-gray-800 dark:text-white/90">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                <p class="text-theme-xs mt-4 border-t border-gray-100 pt-4 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                    {{ $procurementRequest->source->description() }}
                </p>
            </div>
        </div>

        {{-- Rincian barang --}}
        <div class="col-span-12 xl:col-span-8">
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="px-5 py-5 sm:px-6">
                    <h3 class="text-base font-medium text-gray-800 dark:text-white/90">Rincian Barang</h3>
                </div>

                @if ($procurementRequest->isDiajukan() && auth()->user()->can('kelola-pengadaan'))
                    <form method="POST" action="{{ route('procurement-requests.approve', $procurementRequest) }}">
                        @csrf
                        @method('PATCH')

                        <div class="custom-scrollbar max-w-full overflow-x-auto border-t border-gray-100 dark:border-gray-800">
                            <table class="w-full min-w-[560px]">
                                <thead>
                                    <tr class="border-b border-gray-100 dark:border-gray-800">
                                        @foreach (['Barang', 'Diminta', 'Disetujui'] as $heading)
                                            <th class="px-5 py-3 text-left sm:px-6">
                                                <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</p>
                                            </th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($procurementRequest->details as $detail)
                                        <tr class="border-b border-gray-100 dark:border-gray-800">
                                            <td class="px-5 py-4 sm:px-6">
                                                <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                                    {{ $detail->item?->item_name ?? 'Barang tidak dikenal' }}
                                                </p>
                                                <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $detail->item?->item_code }}</p>
                                            </td>
                                            <td class="px-5 py-4 sm:px-6">
                                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                                    {{ number_format($detail->amount_request, 0, ',', '.') }} {{ $detail->item?->unit->label() }}
                                                </p>
                                            </td>
                                            <td class="px-5 py-4 sm:px-6">
                                                <input type="number" min="0" max="{{ $detail->amount_request }}"
                                                    name="granted[{{ $detail->procurement_request_detail_id }}]"
                                                    value="{{ old('granted.'.$detail->procurement_request_detail_id, $detail->amount_request) }}"
                                                    class="h-10 w-28 rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90" />
                                                @error('granted.'.$detail->procurement_request_detail_id)
                                                    <p class="text-theme-xs text-error-500 mt-1">{{ $message }}</p>
                                                @enderror
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @error('granted')
                            <p class="text-theme-xs text-error-500 px-5 pt-3 sm:px-6">{{ $message }}</p>
                        @enderror

                        <div class="flex justify-end gap-3 px-5 py-5 sm:px-6">
                            <button type="submit" form="tolak-usulan"
                                class="bg-error-500 hover:bg-error-600 rounded-lg px-5 py-2.5 text-sm font-medium text-white transition">
                                Tolak
                            </button>
                            <button type="submit"
                                class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 rounded-lg px-5 py-2.5 text-sm font-medium text-white transition">
                                Setujui
                            </button>
                        </div>
                    </form>

                    <form id="tolak-usulan" method="POST" action="{{ route('procurement-requests.reject', $procurementRequest) }}">
                        @csrf
                        @method('PATCH')
                    </form>
                @else
                    <div class="custom-scrollbar max-w-full overflow-x-auto border-t border-gray-100 dark:border-gray-800">
                        <table class="w-full min-w-[560px]">
                            <thead>
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    @foreach (['Barang', 'Diminta', 'Disetujui'] as $heading)
                                        <th class="px-5 py-3 text-left sm:px-6">
                                            <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</p>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($procurementRequest->details as $detail)
                                    <tr class="border-b border-gray-100 dark:border-gray-800">
                                        <td class="px-5 py-4 sm:px-6">
                                            <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                                {{ $detail->item?->item_name ?? 'Barang tidak dikenal' }}
                                            </p>
                                            <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $detail->item?->item_code }}</p>
                                        </td>
                                        <td class="px-5 py-4 sm:px-6">
                                            <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                                {{ number_format($detail->amount_request, 0, ',', '.') }} {{ $detail->item?->unit->label() }}
                                            </p>
                                        </td>
                                        <td class="px-5 py-4 sm:px-6">
                                            <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                                @if (! $detail->sudahDiputuskan())
                                                    Belum diputuskan
                                                @else
                                                    {{ number_format($detail->amount_granted, 0, ',', '.') }} {{ $detail->item?->unit->label() }}
                                                @endif
                                            </p>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
