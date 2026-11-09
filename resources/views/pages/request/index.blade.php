@extends('layouts.app')

@use('App\Enums\ModulPengajuan')
@use('App\Models\Request', 'Permintaan')
@use('App\Support\Warna')

@php
    $modul = ModulPengajuan::Permintaan;
    // Pindah tab atau status membuang filter tahap/status dan halaman, tetapi
    // mempertahankan pencarian, tipe, dan rentang tanggal.
    $filterUrl = fn (string $key, string $value) => route('request.index', array_filter(
        [...request()->except(['tahap', 'status', 'page']), $key => $value],
        fn ($value) => $value !== '' && $value !== null,
    ));
    $tabNav = collect($tabs)->map(fn (array $tab, string $key) => [
        ...$tab,
        'url' => $filterUrl('tahap', $key),
        'aktif' => $tahap === $key && $status === '',
    ])->values()->all();
    $adaFilter = $keyword !== '' || $type !== '' || $status !== '' || $dateFrom !== '' || $dateTo !== '';
    $tombolGaris = 'text-theme-xs rounded-lg px-3 py-1.5 font-medium ring-1 ring-inset transition';
@endphp

@section('content')
    <x-modul.judul :modul="$modul"
        :deskripsi="$isUser ? 'Pantau permintaan barang yang Anda ajukan, dari persetujuan sampai barang diterima.' : 'Kelola dan pantau permintaan barang dari unit kerja.'">
        <a href="{{ $modul->ruteBuat() }}"
            class="shadow-theme-xs inline-flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium transition {{ $modul->tombolClass() }}">
            <x-ui.icon name="plus" class="size-5" />
            {{ $modul->labelBuat() }}
        </a>
    </x-modul.judul>

    <x-modul.catatan :modul="$modul" class="mb-6" />

    <x-ui.flash />

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3 md:gap-6">
        @foreach ([
            ['label' => 'Sedang Berjalan', 'value' => $summary['pending'], 'icon' => 'clock', 'warna' => 'warning'],
            ['label' => 'Diajukan Bulan Ini', 'value' => $summary['bulan_ini'], 'icon' => 'file-text', 'warna' => $modul->warna()],
            ['label' => 'Selesai Bulan Ini', 'value' => $summary['selesai_bulan_ini'], 'icon' => 'check-circle', 'warna' => 'success'],
        ] as $card)
            <div class="flex items-center gap-4 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl {{ Warna::lembut($card['warna']) }}">
                    <x-ui.icon :name="$card['icon']" class="size-5" />
                </span>
                <div>
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                    <p class="text-title-sm font-bold text-gray-800 dark:text-white/90">{{ $card['value'] }}</p>
                </div>
            </div>
        @endforeach
    </div>

    <div class="rounded-2xl border border-t-[3px] border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03] {{ $modul->aksenClass() }}">
        {{-- Tab tahap --}}
        <div class="border-b border-gray-100 px-3 dark:border-gray-800 sm:px-4">
            @include('pages.request._tabs', ['tabs' => $tabNav, 'ariaLabel' => 'Tahap permintaan'])
        </div>

        <form method="GET" action="{{ route('request.index') }}"
            class="grid grid-cols-1 gap-4 border-b border-gray-100 p-5 sm:grid-cols-2 xl:grid-cols-5 dark:border-gray-800">
            @if ($tahap !== '')
                <input type="hidden" name="tahap" value="{{ $tahap }}">
            @endif

            <x-form.text-field name="q" label="Cari" :value="$keyword" placeholder="Nomor, No. ND, atau pemohon" />

            <x-form.select-field name="type" label="Tipe" :options="['normal' => 'Normal', 'emergency' => 'Darurat']"
                :selected="$type" placeholder="Semua tipe" />

            <x-form.select-field name="status" label="Status rinci"
                :options="Arr::except(Permintaan::STATUS_LABELS, 'merged')"
                :selected="$status" placeholder="Semua status" />

            <x-form.date-picker name="date_from" label="Dari Tanggal" placeholder="Pilih tanggal"
                :defaultDate="$dateFrom" dateFormat="Y-m-d" />

            <x-form.date-picker name="date_to" label="Sampai Tanggal" placeholder="Pilih tanggal"
                :defaultDate="$dateTo" dateFormat="Y-m-d" />

            <div class="flex items-end gap-3 sm:col-span-2 xl:col-span-5">
                <button type="submit"
                    class="shadow-theme-xs inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition {{ $modul->tombolClass() }}">
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

        {{-- Keterangan warna tiap status, sekaligus pintasan saring status rinci. --}}
        <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-5 py-3 dark:border-gray-800">
            <span class="text-theme-xs mr-1 text-gray-500 dark:text-gray-400">Keterangan status:</span>
            @foreach (Arr::except(Permintaan::STATUS_LABELS, 'merged') as $kode => $label)
                <a href="{{ $filterUrl('status', $status === $kode ? '' : $kode) }}"
                    title="{{ $status === $kode ? 'Hapus saringan status' : 'Tampilkan hanya status ini' }}">
                    <x-ui.pill :warna="Permintaan::statusWarna($kode)" :ikon="Permintaan::statusIcon($kode)"
                        @class(['transition hover:opacity-80', 'ring-1 ring-current' => $status === $kode])>
                        {{ $label }}
                    </x-ui.pill>
                </a>
            @endforeach
        </div>

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
                    <a href="{{ $modul->ruteBuat() }}"
                        class="mt-5 inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition {{ $modul->tombolClass() }}">
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
                                {{-- Garis kiri berwarna status; kotak ikon menandai jenisnya. --}}
                                <td class="border-l-[3px] px-5 py-4 {{ Warna::garis(Permintaan::statusWarna($row->status)) }}">
                                    <div class="flex items-start gap-3">
                                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg {{ Warna::lembut($row->jenisWarna()) }}"
                                            title="Permintaan {{ strtolower($row->jenisLabel()) }}">
                                            <x-ui.icon :name="$row->jenisIcon()" class="size-[18px]" />
                                        </span>
                                        <div class="min-w-0">
                                            <a href="{{ route('request.show', $row) }}" class="text-theme-sm font-semibold text-gray-800 hover:text-brand-600 dark:text-white/90 dark:hover:text-brand-400">
                                                {{ $row->request_number }}
                                            </a>
                                            <p class="text-theme-xs mt-0.5 text-gray-500 dark:text-gray-400">{{ $row->nd_number ?? 'Nota Dinas belum bernomor' }}</p>
                                            @if ($row->type === 'emergency')
                                                <x-ui.pill :warna="$row->jenisWarna()" :ikon="$row->jenisIcon()" class="mt-1.5">Darurat</x-ui.pill>
                                            @endif
                                        </div>
                                    </div>
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
                                    <x-ui.pill :warna="Permintaan::statusWarna($row->status)" :ikon="Permintaan::statusIcon($row->status)" class="whitespace-nowrap">
                                        {{ $row->statusLabel() }}
                                    </x-ui.pill>
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
                                                        ($isKasubbag && ($row->status === 'processed_by_staff' || $isEmergencyReadyToComplete));
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
                        <a href="{{ route('request.show', $row) }}"
                            class="flex items-start gap-3 border-l-[3px] px-5 py-4 transition hover:bg-gray-50 dark:hover:bg-white/[0.02] {{ Warna::garis(Permintaan::statusWarna($row->status)) }}">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg {{ Warna::lembut($row->jenisWarna()) }}">
                                <x-ui.icon :name="$row->jenisIcon()" class="size-[18px]" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-theme-sm font-semibold text-gray-800 dark:text-white/90">{{ $row->request_number }}</p>
                                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                            {{ $row->user?->fullname ?? '-' }} · {{ $row->date?->translatedFormat('d M Y') ?? '-' }}
                                        </p>
                                    </div>
                                    <x-ui.pill :warna="Permintaan::statusWarna($row->status)" :ikon="Permintaan::statusIcon($row->status)" class="shrink-0">
                                        {{ $row->statusLabel() }}
                                    </x-ui.pill>
                                </div>
                                <p class="text-theme-sm mt-2 truncate text-gray-700 dark:text-gray-300">{{ $row->ringkasanBarang() }}</p>
                                @if ($row->type === 'emergency')
                                    <x-ui.pill :warna="$row->jenisWarna()" :ikon="$row->jenisIcon()" class="mt-2">Darurat</x-ui.pill>
                                @endif
                            </div>
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
