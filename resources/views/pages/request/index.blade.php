@extends('layouts.app')

@php
    // Pindah tab membuang filter status tunggal dan halaman, tetapi
    // mempertahankan pencarian, tipe, dan rentang tanggal.
    $tabUrl = fn (string $key) => route('request.index', array_filter(
        [...request()->except(['tahap', 'status', 'page']), 'tahap' => $key],
        fn ($value) => $value !== '' && $value !== null,
    ));
    $adaFilter = $keyword !== '' || $type !== '' || $status !== '' || $dateFrom !== '' || $dateTo !== '';
    $tombolGaris = 'text-theme-xs rounded-lg px-3 py-1.5 font-medium ring-1 ring-inset transition';
@endphp

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Permintaan Barang</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ $isUser ? 'Pantau permintaan barang yang Anda ajukan, dari persetujuan sampai barang diterima.' : 'Kelola dan pantau permintaan barang dari unit kerja.' }}
            </p>
        </div>

        <a href="{{ route('request.create') }}"
            class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium text-white transition">
            <x-ui.icon name="plus" class="size-5" />
            Buat Permintaan
        </a>
    </div>

    <x-ui.flash />

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3 md:gap-6">
        @foreach ([
            ['label' => 'Sedang Berjalan', 'value' => $summary['pending'], 'icon' => 'inbox', 'chip' => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400'],
            ['label' => 'Diajukan Bulan Ini', 'value' => $summary['bulan_ini'], 'icon' => 'file-text', 'chip' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400'],
            ['label' => 'Selesai Bulan Ini', 'value' => $summary['selesai_bulan_ini'], 'icon' => 'check-circle', 'chip' => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500'],
        ] as $card)
            <div class="flex items-center gap-4 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl {{ $card['chip'] }}">
                    <x-ui.icon :name="$card['icon']" class="size-5" />
                </span>
                <div>
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                    <p class="text-title-sm font-bold text-gray-800 dark:text-white/90">{{ $card['value'] }}</p>
                </div>
            </div>
        @endforeach
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        {{-- Tab status --}}
        <nav class="custom-scrollbar flex gap-1 overflow-x-auto border-b border-gray-100 px-3 dark:border-gray-800 sm:px-4" aria-label="Tahap permintaan">
            @foreach ($tabs as $key => $tab)
                @php $aktif = $tahap === $key && $status === ''; @endphp
                <a href="{{ $tabUrl($key) }}" @if ($aktif) aria-current="page" @endif
                    @class([
                        'text-theme-sm -mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-3 py-3.5 font-medium transition',
                        'border-brand-500 text-brand-600 dark:text-brand-400' => $aktif,
                        'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300' => ! $aktif,
                    ])>
                    {{ $tab['label'] }}
                    <span @class([
                        'text-theme-xs rounded-full px-2 py-0.5',
                        'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400' => $aktif,
                        'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400' => ! $aktif,
                    ])>{{ $tab['jumlah'] }}</span>
                </a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('request.index') }}"
            class="grid grid-cols-1 gap-4 border-b border-gray-100 p-5 sm:grid-cols-2 xl:grid-cols-5 dark:border-gray-800">
            @if ($tahap !== '')
                <input type="hidden" name="tahap" value="{{ $tahap }}">
            @endif

            <x-form.text-field name="q" label="Cari" :value="$keyword" placeholder="Nomor, No. ND, atau pemohon" />

            <x-form.select-field name="type" label="Tipe" :options="['normal' => 'Normal', 'emergency' => 'Darurat']"
                :selected="$type" placeholder="Semua tipe" />

            <x-form.select-field name="status" label="Status rinci"
                :options="Arr::except(\App\Models\Request::STATUS_LABELS, 'merged')"
                :selected="$status" placeholder="Semua status" />

            <x-form.date-picker name="date_from" label="Dari Tanggal" placeholder="Pilih tanggal"
                :defaultDate="$dateFrom" dateFormat="Y-m-d" />

            <x-form.date-picker name="date_to" label="Sampai Tanggal" placeholder="Pilih tanggal"
                :defaultDate="$dateTo" dateFormat="Y-m-d" />

            <div class="flex items-end gap-3 sm:col-span-2 xl:col-span-5">
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                    <x-ui.icon name="search" class="size-4" />
                    Terapkan
                </button>
                @if ($adaFilter)
                    <a href="{{ route('request.index', $tahap !== '' ? ['tahap' => $tahap] : []) }}"
                        class="text-theme-sm inline-flex items-center rounded-lg px-4 py-2.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                        Reset
                    </a>
                @endif
            </div>
        </form>

        @if ($requests->isEmpty())
            <div class="flex flex-col items-center px-5 py-14 text-center">
                <span class="flex size-14 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-white/5">
                    <x-ui.icon name="inbox" class="size-7" />
                </span>
                <p class="mt-4 text-sm font-medium text-gray-800 dark:text-white/90">
                    {{ $adaFilter || $tahap !== '' ? 'Tidak ada permintaan yang cocok.' : 'Belum ada permintaan.' }}
                </p>
                <p class="text-theme-sm mt-1 text-gray-500 dark:text-gray-400">
                    {{ $adaFilter || $tahap !== '' ? 'Coba ubah tab atau penyaring.' : 'Permintaan yang diajukan akan tampil di sini.' }}
                </p>
                @unless ($adaFilter || $tahap !== '')
                    <a href="{{ route('request.create') }}"
                        class="bg-brand-500 hover:bg-brand-600 mt-5 inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                        <x-ui.icon name="plus" class="size-4" />
                        Buat Permintaan
                    </a>
                @endunless
            </div>
        @else
            {{-- Layar lebar: tabel lengkap dengan aksi. --}}
            <div class="custom-scrollbar hidden max-w-full overflow-x-auto lg:block">
                <table class="w-full min-w-[960px]">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50/70 dark:border-gray-800 dark:bg-white/[0.02]">
                            @foreach (['Permintaan', 'Pemohon', 'Barang', 'Tanggal', 'Status', 'Aksi'] as $heading)
                                <th class="px-5 py-3 text-left">
                                    <p class="text-theme-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">{{ $heading }}</p>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($requests as $row)
                            <tr class="align-top transition hover:bg-gray-50/60 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4">
                                    <a href="{{ route('request.show', $row) }}" class="text-theme-sm font-semibold text-gray-800 hover:text-brand-600 dark:text-white/90 dark:hover:text-brand-400">
                                        {{ $row->request_number }}
                                    </a>
                                    <p class="text-theme-xs mt-0.5 text-gray-500 dark:text-gray-400">{{ $row->nd_number ?? 'Nota Dinas belum bernomor' }}</p>
                                    @if ($row->type === 'emergency')
                                        <span class="text-theme-xs bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400 mt-1.5 inline-block rounded-full px-2 py-0.5 font-medium">Darurat</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        @if ($row->user)
                                            <x-ui.user-avatar :user="$row->user" class="size-9 text-xs" />
                                        @endif
                                        <div class="min-w-0">
                                            <p class="text-theme-sm truncate font-medium text-gray-800 dark:text-white/90">{{ $row->user?->fullname ?? '-' }}</p>
                                            <p class="text-theme-xs truncate text-gray-500 dark:text-gray-400">{{ $row->user?->unit?->label() ?? '-' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="max-w-[16rem] px-5 py-4">
                                    <p class="text-theme-sm truncate text-gray-700 dark:text-gray-300">{{ $row->ringkasanBarang() }}</p>
                                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $row->item->count() }} jenis barang</p>
                                </td>
                                <td class="text-theme-sm px-5 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400">
                                    {{ $row->date?->translatedFormat('d M Y') ?? '-' }}
                                </td>
                                <td class="px-5 py-4">
                                    <span class="text-theme-xs inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-medium whitespace-nowrap {{ \App\Models\Request::statusBadgeClass($row->status) }}">
                                        <span class="size-1.5 rounded-full bg-current"></span>
                                        {{ $row->statusLabel() }}
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <a href="{{ route('request.show', $row) }}"
                                            class="{{ $tombolGaris }} text-gray-700 ring-gray-300 hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                            Detail
                                        </a>

                                        @php
                                            $isEmergencyReadyToComplete = $row->type === 'emergency' && $row->status === 'approved_by_head';
                                            $hasItemProposals = $row->item->contains(fn ($detail) => $detail->item_proposal_id !== null);
                                            $isKasubbagKepalaUnit = $isKasubbag
                                                && auth()->user()->unit === \App\Enums\UnitKerja::SubbagianUmumTi;
                                            $canRespond = ($row->status === 'submitted' && ($isKepalaUnit || $isKasubbagKepalaUnit)) ||
                                                        (in_array($row->status, ['approved_by_head', 'in_issuance'], true) && $isPengelolaGudang) ||
                                                        ($isAdmin && in_array($row->status, ['submitted', 'approved_by_head', 'processed_by_staff', 'in_issuance'], true)) ||
                                                        ($isKasubbag && (in_array($row->status, ['processed_by_staff', 'in_issuance'], true) || $isEmergencyReadyToComplete));
                                            $canRespond = $canRespond && (! $hasItemProposals || ($isAdmin && $row->status === 'submitted'));
                                            // Permintaan darurat juga boleh diubah kuantitasnya (mis. stok kurang).
                                            $canEditQuantity = $row->status === 'approved_by_head'
                                                && ($isPengelolaGudang || $isAdmin || ($isKasubbag && $row->type === 'emergency'))
                                                && ! $hasItemProposals;
                                            $revertTo = $isAdmin ? $row->previousStatus() : null;
                                        @endphp

                                        @if ($canEditQuantity)
                                            <a href="{{ route('request.edit', $row) }}"
                                                class="{{ $tombolGaris }} text-warning-600 dark:text-warning-500 ring-warning-200 dark:ring-warning-500/30 hover:bg-warning-50 dark:hover:bg-warning-500/10">
                                                Ubah Kuantitas
                                            </a>
                                        @endif

                                        @if ($canRespond)
                                            @php
                                                $approveLabel = $isEmergencyReadyToComplete || $row->status === 'in_issuance' ? 'Serahkan Barang' : ($row->status === 'processed_by_staff' ? 'Mulai Pengeluaran' : 'Setujui');
                                                $respondContext = [
                                                    'id' => $row->request_id,
                                                    'number' => $row->request_number,
                                                    'needsNd' => $row->status === 'approved_by_head' && $row->nd_number === null,
                                                    'preview' => $row->documentNumber('ND'),
                                                    'sequence' => $row->ndSequence(),
                                                ];
                                            @endphp
                                            {{-- Keduanya lewat modal keputusan: catatan wajib saat
                                                 menolak, opsional saat menyetujui. --}}
                                            <button type="button" x-data
                                                @click="$dispatch('open-respond-modal', @js([...$respondContext, 'action' => 'approve', 'label' => $approveLabel]))"
                                                class="{{ $tombolGaris }} text-success-600 dark:text-success-500 ring-success-200 dark:ring-success-500/30 hover:bg-success-50 dark:hover:bg-success-500/10">
                                                {{ $approveLabel }}
                                            </button>
                                            <button type="button" x-data
                                                @click="$dispatch('open-respond-modal', @js([...$respondContext, 'action' => 'reject', 'label' => 'Tolak']))"
                                                class="{{ $tombolGaris }} text-error-600 dark:text-error-500 ring-error-200 dark:ring-error-500/30 hover:bg-error-50 dark:hover:bg-error-500/10">
                                                Tolak
                                            </button>
                                        @endif

                                        @if ($revertTo)
                                            <button type="button" x-data
                                                @click="$dispatch('open-revert-modal', @js(['id' => $row->request_id, 'number' => $row->request_number, 'from' => $row->status, 'to' => $revertTo]))"
                                                class="{{ $tombolGaris }} text-gray-700 ring-gray-300 hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                                Kembalikan Status
                                            </button>
                                        @endif

                                        @if ($hasItemProposals && ($isAdmin || $isPengelolaGudang))
                                            <a href="{{ route('item-proposals.index') }}"
                                                class="text-theme-xs text-warning-600 underline decoration-warning-300 underline-offset-2 hover:text-warning-700 dark:text-warning-400 dark:decoration-warning-500/50 dark:hover:text-warning-300">
                                                Selesaikan usulan barang
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Layar sempit: kartu ringkas; aksinya ada di halaman detail. --}}
            <ul class="divide-y divide-gray-100 lg:hidden dark:divide-gray-800">
                @foreach ($requests as $row)
                    <li>
                        <a href="{{ route('request.show', $row) }}" class="block px-5 py-4 transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-theme-sm font-semibold text-gray-800 dark:text-white/90">{{ $row->request_number }}</p>
                                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $row->user?->fullname ?? '-' }} · {{ $row->date?->translatedFormat('d M Y') ?? '-' }}
                                    </p>
                                </div>
                                <span class="text-theme-xs inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 font-medium {{ \App\Models\Request::statusBadgeClass($row->status) }}">
                                    <span class="size-1.5 rounded-full bg-current"></span>
                                    {{ $row->statusLabel() }}
                                </span>
                            </div>
                            <p class="text-theme-sm mt-2 truncate text-gray-700 dark:text-gray-300">{{ $row->ringkasanBarang() }}</p>
                            @if ($row->type === 'emergency')
                                <span class="text-theme-xs bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400 mt-2 inline-block rounded-full px-2 py-0.5 font-medium">Darurat</span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>

            {{ $requests->links('vendor.pagination.tailadmin') }}
        @endif
    </div>

    @if ($isAdmin)
        @include('pages.request._revert-status-modal')
    @endif

    @if ($isAdmin || $isPengelolaGudang || $isKasubbag || $isKepalaUnit)
        @include('pages.request._respond-modal')
    @endif
@endsection
