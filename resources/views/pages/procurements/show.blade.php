@extends('layouts.app')

@section('content')
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">{{ $procurement->judul() }}</h2>
            <nav class="mt-1.5">
                <ol class="flex items-center gap-1.5">
                    <li>
                        <a href="{{ route('procurements.index') }}"
                            class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                            Realisasi Pengadaan
                            <svg class="stroke-current" width="17" height="16" viewBox="0 0 17 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M6.0765 12.667L10.2432 8.50033L6.0765 4.33366" stroke="" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                    </li>
                    <li class="text-sm text-gray-800 dark:text-white/90">{{ $procurement->judul() }}</li>
                </ol>
            </nav>
        </div>

        <span class="text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium {{ $procurement->status->badgeClass() }}">
            {{ $procurement->status->label() }}
        </span>
    </div>

    <x-ui.flash />

    <div class="grid grid-cols-12 gap-4 md:gap-6">
        {{-- Identitas realisasi --}}
        <div class="col-span-12 space-y-4 md:space-y-6 xl:col-span-4">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                <dl class="space-y-3">
                    @foreach ([
                        'Usulan Asal' => $procurement->procurementRequest?->judul() ?? '—',
                        'Penyedia' => $procurement->supplier?->supplier_name ?? '—',
                        'Tanggal Realisasi' => $procurement->procurement_date->translatedFormat('d F Y'),
                        'Direalisasikan Oleh' => $procurement->user?->fullname ?? '—',
                        'Nilai Total' => 'Rp'.number_format($procurement->nilaiTotal(), 0, ',', '.'),
                    ] as $label => $value)
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="text-theme-sm text-right font-medium text-gray-800 dark:text-white/90">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if ($procurement->isMenungguPenerimaan())
                    @can('kelola-gudang')
                        <a href="{{ route('procurement-receipts.create', $procurement) }}"
                            class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 mt-4 flex w-full items-center justify-center rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                            Konfirmasi Penerimaan
                        </a>
                    @endcan
                @endif
            </div>

            @if ($procurement->isDiterima() && $procurement->receipt)
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                    <h3 class="text-base font-medium text-gray-800 dark:text-white/90">Penerimaan (BAST)</h3>

                    <dl class="mt-3 space-y-3">
                        @foreach ([
                            'Nomor BAST' => $procurement->receipt->bast_number,
                            'Diterima Oleh' => $procurement->receipt->receivedBy?->fullname ?? '—',
                            'Tanggal Diterima' => $procurement->receipt->received_at->translatedFormat('d F Y'),
                        ] as $label => $value)
                            <div class="flex items-start justify-between gap-4">
                                <dt class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                <dd class="text-theme-sm text-right font-medium text-gray-800 dark:text-white/90">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($procurement->receipt->notes)
                        <p class="text-theme-xs mt-4 border-t border-gray-100 pt-4 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                            {{ $procurement->receipt->notes }}
                        </p>
                    @endif

                    @if ($procurement->diterimaSebagian())
                        <p class="text-theme-xs bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400 mt-4 rounded-lg p-3">
                            Sebagian barang datang kurang dari yang dipesan. Lihat rincian di sebelah.
                        </p>
                    @endif
                </div>
            @endif
        </div>

        {{-- Rincian barang --}}
        <div class="col-span-12 xl:col-span-8">
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="px-5 py-5 sm:px-6">
                    <h3 class="text-base font-medium text-gray-800 dark:text-white/90">Rincian Barang</h3>
                </div>

                <div class="custom-scrollbar max-w-full overflow-x-auto border-t border-gray-100 dark:border-gray-800">
                    <table class="w-full min-w-[720px]">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                @foreach (['Barang', 'Dipesan', 'Harga Satuan', 'Subtotal', 'Diterima'] as $heading)
                                    <th class="px-5 py-3 text-left sm:px-6">
                                        <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</p>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($procurement->details as $detail)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="px-5 py-4 sm:px-6">
                                        <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                            {{ $detail->item?->item_name ?? 'Barang tidak dikenal' }}
                                        </p>
                                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $detail->item?->item_code }}</p>
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                            {{ number_format($detail->amount, 0, ',', '.') }} {{ $detail->item?->unit->label() }}
                                        </p>
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                            Rp{{ number_format($detail->unit_price, 0, ',', '.') }}
                                        </p>
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                            Rp{{ number_format($detail->subtotal(), 0, ',', '.') }}
                                        </p>
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                            @if (! $detail->sudahDiterima())
                                                Belum diterima
                                            @else
                                                {{ number_format($detail->amount_received, 0, ',', '.') }} {{ $detail->item?->unit->label() }}
                                                @if ($detail->kurangKirim() > 0)
                                                    <span class="text-warning-600 dark:text-warning-400 block">
                                                        kurang {{ number_format($detail->kurangKirim(), 0, ',', '.') }}
                                                    </span>
                                                @endif
                                            @endif
                                        </p>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
