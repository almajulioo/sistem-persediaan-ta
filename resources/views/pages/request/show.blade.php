@extends('layouts.app')

@section('content')
    @php
        $isEmergencyReadyToComplete = $requestData->type === 'emergency' && $requestData->status === 'approved_by_head';
        $hasItemProposals = $requestData->hasItemProposals();
        // Permintaan darurat juga boleh diubah kuantitasnya (mis. stok kurang),
        // termasuk oleh Kasubbag yang ikut menyelesaikannya.
        $canEditQuantity = $requestData->status === 'approved_by_head'
            && ($isPengelolaGudang || $isAdmin || ($isKasubbag && $requestData->type === 'emergency'))
            && ! $hasItemProposals;
        $isKasubbagKepalaUnit = $isKasubbag
            && auth()->user()->unit === \App\Enums\UnitKerja::SubbagianUmumTi;
        $canRespond = ($requestData->status === 'submitted' && ($isKepalaUnit || $isKasubbagKepalaUnit)) ||
                    (in_array($requestData->status, ['approved_by_head', 'in_issuance'], true) && $isPengelolaGudang) ||
                    ($isAdmin && in_array($requestData->status, ['submitted', 'approved_by_head', 'processed_by_staff', 'in_issuance'], true)) ||
                    ($isKasubbag && ($requestData->status === 'processed_by_staff' || $isEmergencyReadyToComplete));
        $canRespond = $canRespond
            && $requestData->type !== 'umum'
            && (! $hasItemProposals || ($isAdmin && $requestData->status === 'submitted'));
        $revertTo = $isAdmin ? $requestData->previousStatus() : null;
        $approveLabel = $isEmergencyReadyToComplete || $requestData->status === 'in_issuance' ? 'Serahkan Barang' : ($requestData->status === 'processed_by_staff' ? 'Mulai Pengeluaran' : 'Setujui');

        // Setelah diserahkan: pemohon mengonfirmasi atau melaporkan masalah,
        // Gudang/Admin menanggapi atau menutup atas nama pemohon.
        $isDelivered = $requestData->status === 'delivered';
        $isRequester = $isDelivered && auth()->user()->user_id === $requestData->user_id;
        $isReceiptOfficer = $isDelivered && auth()->user()->can('kelola-permintaan');
        $hasOpenProblem = $isDelivered && $requestData->hasOpenReceiptProblem();
        $canCloseOnBehalf = $isReceiptOfficer && $requestData->canBeClosedOnBehalf();
        $respondContext = [
            'id' => $requestData->request_id,
            'number' => $requestData->request_number,
            'needsNd' => $requestData->status === 'approved_by_head' && $requestData->nd_number === null,
            'preview' => $requestData->documentNumber('ND'),
            'sequence' => $requestData->ndSequence(),
        ];
    @endphp



    <x-modul.judul :modul="\App\Enums\ModulPengajuan::Permintaan" judul="Detail Permintaan">
        <x-slot:bawah>
            <nav class="mt-1">
                <ol class="flex items-center gap-1.5">
                    <li>
                        <a href="{{ route('request.index') }}"
                            class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-teal-600 dark:text-gray-400">
                            Daftar Permintaan
                            <svg class="stroke-current" width="17" height="16" viewBox="0 0 17 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M6.0765 12.667L10.2432 8.50033L6.0765 4.33366" stroke="" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                    </li>
                    <li class="text-sm text-gray-800 dark:text-white/90">{{ $requestData->request_number }}</li>
                </ol>
            </nav>
        </x-slot:bawah>

            @if ($hasNotaDinas)
                <a href="{{ route('request.nota-dinas', $requestData) }}" target="_blank" rel="noopener"
                    class="rounded-lg px-4 py-2.5 text-sm font-medium {{ \App\Enums\ModulPengajuan::Permintaan->tombolClass() }}">
                    Lihat Nota Dinas PDF
                </a>
            @endif
            @if ($hasBast)
                <a href="{{ route('request.bast', $requestData) }}" target="_blank" rel="noopener"
                    class="rounded-lg bg-success-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-success-600">
                    Lihat BAST PDF
                </a>
            @endif
    </x-modul.judul>

    <x-ui.flash />

    @if ($isDelivered)
        <div class="mb-6 rounded-2xl border border-brand-200 bg-brand-50 p-5 dark:border-brand-500/30 dark:bg-brand-500/10">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-2xl">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">
                        {{ $hasOpenProblem ? 'Masalah penerimaan menunggu tanggapan Pengelola Gudang' : 'Barang sudah diserahkan, menunggu konfirmasi pemohon' }}
                    </h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                        Diserahkan {{ $requestData->delivered_at?->translatedFormat('d F Y, H:i') ?? '-' }}.
                        @if ($isRequester)
                            Periksa barang yang Anda terima sesuai kolom Disetujui, lalu konfirmasi penerimaannya, atau laporkan bila ada yang kurang, rusak, atau belum sampai.
                        @elseif ($isReceiptOfficer && ! $hasOpenProblem && ! $canCloseOnBehalf && $requestData->closableOnBehalfFrom())
                            Bila pemohon tidak mengonfirmasi, permintaan dapat ditutup atas namanya mulai {{ $requestData->closableOnBehalfFrom()->translatedFormat('d F Y') }}.
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    @if ($isRequester)
                        <button type="button" x-data
                            @click="$dispatch('open-receipt-modal', @js([
                                'action' => 'confirm',
                                'label' => 'Konfirmasi Diterima',
                                'title' => 'Barang sudah Anda terima?',
                                'description' => 'Permintaan akan ditutup sebagai Selesai.',
                                'required' => false,
                                'placeholder' => 'mis. diterima lengkap oleh staf kami',
                                'danger' => false,
                            ]))"
                            class="bg-success-500 hover:bg-success-600 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                            Konfirmasi Diterima
                        </button>
                        <button type="button" x-data
                            @click="$dispatch('open-receipt-modal', @js([
                                'action' => 'report',
                                'label' => 'Laporkan Masalah',
                                'title' => 'Laporkan masalah penerimaan',
                                'description' => 'Pengelola Gudang akan menindaklanjuti dan menanggapi laporan Anda.',
                                'required' => true,
                                'placeholder' => 'mis. kertas yang diterima hanya 3 rim dari 5 rim',
                                'danger' => true,
                            ]))"
                            class="text-error-600 dark:text-error-500 ring-error-200 dark:ring-error-500/30 hover:bg-error-50 dark:hover:bg-error-500/10 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium ring-1 ring-inset transition">
                            Laporkan Masalah
                        </button>
                    @endif

                    @if ($isReceiptOfficer && $hasOpenProblem)
                        <button type="button" x-data
                            @click="$dispatch('open-receipt-modal', @js([
                                'action' => 'reply',
                                'label' => 'Kirim Tanggapan',
                                'title' => 'Tanggapi masalah penerimaan',
                                'description' => 'Tanggapan dikirim ke pemohon. Bila barang memang kurang, serahkan kekurangannya lebih dulu.',
                                'required' => true,
                                'placeholder' => 'mis. kekurangan 2 rim sudah diserahkan hari ini',
                                'danger' => false,
                            ]))"
                            class="bg-brand-500 hover:bg-brand-600 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                            Tanggapi Masalah
                        </button>
                    @endif

                    @if ($canCloseOnBehalf)
                        <button type="button" x-data
                            @click="$dispatch('open-receipt-modal', @js([
                                'action' => 'close',
                                'label' => 'Tutup atas Nama Pemohon',
                                'title' => 'Tutup atas nama pemohon?',
                                'description' => 'Pemohon belum mengonfirmasi penerimaan. Permintaan akan ditutup sebagai Selesai dan alasannya dikirim ke pemohon.',
                                'required' => true,
                                'placeholder' => 'mis. barang diambil langsung oleh pemohon, konfirmasi tidak kunjung diberikan',
                                'danger' => false,
                            ]))"
                            class="text-theme-sm inline-flex items-center rounded-lg px-4 py-2.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                            Tutup atas Nama Pemohon
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif


    @if ($canRespond || $canEditQuantity || $revertTo)
        <div class="mt-6  p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex flex-wrap items-center justify-end gap-3">
                @if ($revertTo)
                    <button type="button" x-data
                        @click="$dispatch('open-revert-modal', @js(['id' => $requestData->request_id, 'number' => $requestData->request_number, 'from' => $requestData->status, 'to' => $revertTo]))"
                        class="text-theme-sm inline-flex items-center rounded-lg px-4 py-2.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                        Kembalikan Status
                    </button>
                @endif

                @if ($canEditQuantity)
                    <a href="{{ route('request.edit', $requestData) }}"
                        class="text-theme-sm text-warning-600 dark:text-warning-500 ring-warning-200 dark:ring-warning-500/30 hover:bg-warning-50 dark:hover:bg-warning-500/10 inline-flex items-center rounded-lg px-4 py-2.5 font-medium ring-1 ring-inset transition">
                        Ubah Kuantitas
                    </a>
                @endif

                @if ($canRespond)
                    {{-- Keduanya lewat modal keputusan: catatan wajib saat
                         menolak, opsional saat menyetujui. --}}
                    <button type="button" x-data
                        @click="$dispatch('open-respond-modal', @js([...$respondContext, 'action' => 'approve', 'label' => $approveLabel]))"
                        class="bg-success-500 hover:bg-success-600 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                        {{ $approveLabel }}
                    </button>
                    <button type="button" x-data
                        @click="$dispatch('open-respond-modal', @js([...$respondContext, 'action' => 'reject', 'label' => 'Tolak']))"
                        class="text-error-600 dark:text-error-500 ring-error-200 dark:ring-error-500/30 hover:bg-error-50 dark:hover:bg-error-500/10 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium ring-1 ring-inset transition">
                        Tolak
                    </button>
                @endif
            </div>
        </div>
    @endif

    <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
        <dl class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Nomor Permintaan</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $requestData->request_number }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Nomor Nota Dinas</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $requestData->nd_number ?? 'Belum bernomor' }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Nama Pemohon</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $requestData->user?->fullname ?? '-' }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Tanggal</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $requestData->date?->translatedFormat('d F Y') ?? '-' }}</dd></div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">Tipe</dt>
                <dd>
                    <x-ui.pill :warna="$requestData->jenisWarna()" :ikon="$requestData->jenisIcon()" class="mt-1">{{ $requestData->jenisLabel() }}</x-ui.pill>
                </dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">Status</dt>
                <dd>
                    <x-ui.pill :warna="\App\Models\Request::statusWarna($requestData->status)" :ikon="\App\Models\Request::statusIcon($requestData->status)" class="mt-1">
                        {{ $requestData->statusLabel() }}
                    </x-ui.pill>
                </dd>
            </div>
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
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400">Diminta</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400">Disetujui</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($requestData->item as $detail)
                    <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $detail->item?->item_code ?? ($detail->itemProposal ? 'Usulan baru' : '-') }}</td>
                        <td class="px-6 py-4 text-sm font-medium text-gray-800 dark:text-white/90">
                            {{ $detail->item?->item_name ?? $detail->itemProposal?->item_name ?? '-' }}
                            @if ($detail->itemProposal)
                                <x-ui.pill :warna="\App\Enums\ItemApplicationType::BarangBaru->warna()" :ikon="\App\Enums\ItemApplicationType::BarangBaru->icon()" class="ml-2">
                                    Usulan {{ $detail->itemProposal->status?->label() ?? $detail->itemProposal->status }}
                                </x-ui.pill>
                                @if ($detail->itemProposal->isDitolak() && ($isAdmin || $isKasubbag || $isPengelolaGudang))
                                    <form method="POST" action="{{ route('request.proposal-detail.remove', [$requestData, $detail]) }}" class="mt-2">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs font-medium text-error-600 hover:text-error-700 dark:text-error-400">
                                            Hapus dari permintaan
                                        </button>
                                    </form>
                                @endif
                            @endif
                        </td>
                        <td class="px-6 py-4 text-right text-sm text-gray-700 dark:text-gray-300">{{ number_format($detail->quantity_requested, 0, ',', '.') }}</td>
                        {{-- Kosong berarti belum diputuskan; 0 berarti tidak diberikan. --}}
                        <td @class([
                            'px-6 py-4 text-right text-sm',
                            'text-gray-400 dark:text-gray-500' => ! $detail->isDecided(),
                            'text-error-600 dark:text-error-400' => $detail->quantity_approved === 0,
                            'text-warning-600 dark:text-warning-400' => $detail->isDecided() && $detail->quantity_approved > 0 && $detail->quantity_approved < $detail->quantity_requested,
                            'text-gray-700 dark:text-gray-300' => $detail->isDecided() && $detail->quantity_approved >= $detail->quantity_requested,
                        ])>
                            @if (! $detail->isDecided())
                                —
                            @elseif ($detail->quantity_approved === 0)
                                0 (tidak diberikan)
                            @else
                                {{ number_format($detail->quantity_approved, 0, ',', '.') }}
                            @endif
                        </td>
                    </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-8 text-center text-sm text-gray-500 dark:text-gray-400">Belum ada barang dalam permintaan ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @include('pages.request._history', ['histories' => $requestData->histories])

    @if ($revertTo)
        @include('pages.request._revert-status-modal')
    @endif

    @if ($canRespond)
        @include('pages.request._respond-modal')
    @endif

    @if ($isRequester || $isReceiptOfficer)
        @include('pages.request._receipt-modal')
    @endif
@endsection