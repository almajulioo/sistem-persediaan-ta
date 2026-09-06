@extends('layouts.app')

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Detail Permintaan</h2>
            <nav class="mt-1.5">
                <ol class="flex items-center gap-1.5">
                    <li>
                        <a href="{{ route('request.index') }}"
                            class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                            Daftar Permintaan
                            <svg class="stroke-current" width="17" height="16" viewBox="0 0 17 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M6.0765 12.667L10.2432 8.50033L6.0765 4.33366" stroke="" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                    </li>
                    <li class="text-sm text-gray-800 dark:text-white/90">{{ $requestData->request_number }}</li>
                </ol>
            </nav>
        </div>
        <a href="{{ route('request.nota-dinas', $requestData) }}" target="_blank" rel="noopener"
            class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">
            Lihat Nota Dinas PDF
        </a>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
        <dl class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Nomor Permintaan</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $requestData->request_number }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Nama Pemohon</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $requestData->user?->fullname ?? '-' }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Tanggal</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $requestData->date?->translatedFormat('d F Y') ?? '-' }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Tipe</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ ucfirst($requestData->type) }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Status</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ ucfirst($requestData->status) }}</dd></div>
        </dl>
    </div>

    <div class="mt-6 rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-800">
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Barang yang Diminta</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[560px]">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400">Kode Barang</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400">Nama Barang</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($requestData->item as $detail)
                    <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $detail->item?->item_code ?? '-' }}</td>
                        <td class="px-6 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $detail->item?->item_name ?? '-' }}</td>
                        <td class="px-6 py-4 text-right text-sm text-gray-700 dark:text-gray-300">{{ number_format($detail->quantity_requested, 0, ',', '.') }}</td>
                    </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-6 py-8 text-center text-sm text-gray-500 dark:text-gray-400">Belum ada barang dalam permintaan ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection