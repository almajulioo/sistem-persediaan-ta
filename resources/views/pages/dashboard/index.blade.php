@extends('layouts.app')

@php
    // Kelas warna ditulis utuh, bukan dirangkai dari nama warna, supaya
    // terbaca pemindai Tailwind. Kunci = `warna` dari DashboardController.
    $chipUbin = [
        'sky' => 'bg-sky-500',
        'brand' => 'bg-brand-500',
        'success' => 'bg-success-500',
        'orange' => 'bg-orange-500',
        'purple' => 'bg-purple-500',
        'amber' => 'bg-amber-500',
        'pink' => 'bg-pink-500',
        'teal' => 'bg-teal-500',
        'indigo' => 'bg-indigo-500',
        'rose' => 'bg-rose-500',
        'gray' => 'bg-gray-500 dark:bg-gray-600',
    ];

    $chipTugas = [
        'sky' => 'bg-sky-50 text-sky-600 dark:bg-sky-500/15 dark:text-sky-400',
        'purple' => 'bg-purple-50 text-purple-600 dark:bg-purple-500/15 dark:text-purple-400',
        'amber' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400',
        'pink' => 'bg-pink-50 text-pink-600 dark:bg-pink-500/15 dark:text-pink-400',
        'orange' => 'bg-orange-50 text-orange-600 dark:bg-orange-500/15 dark:text-orange-400',
        'error' => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
        'success' => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
    ];

    // Isi bilah memakai warna status; lintasannya tingkat yang lebih terang
    // dari warna yang sama, sehingga barang habis (isi nol) tetap terbaca merah.
    $warnaStok = [
        'habis' => ['isi' => 'bg-error-500', 'lintasan' => 'bg-error-100 dark:bg-error-500/20'],
        'menipis' => ['isi' => 'bg-warning-500', 'lintasan' => 'bg-warning-100 dark:bg-warning-500/20'],
        'aman' => ['isi' => 'bg-success-500', 'lintasan' => 'bg-success-100 dark:bg-success-500/20'],
        'berlebih' => ['isi' => 'bg-brand-500', 'lintasan' => 'bg-brand-100 dark:bg-brand-500/20'],
    ];

    $kartu = 'rounded-2xl border border-gray-200 bg-white p-5 md:p-6 dark:border-gray-800 dark:bg-white/[0.03]';
    $judulKartu = 'text-lg font-semibold text-gray-800 dark:text-white/90';
    $rupiah = fn (int $nilai) => 'Rp'.number_format($nilai, 0, ',', '.');
@endphp

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">
                {{ $salam }}, {{ $user->fullname }}
            </h2>
            <span class="text-theme-xs mt-2 inline-flex items-center rounded-full px-2.5 py-1 font-medium {{ $user->role->badgeClass() }}">
                {{ $user->role->label() }}
            </span>
        </div>
        <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ now()->translatedFormat('l, j F Y') }}</p>
    </div>

    <x-ui.flash />

    @if ($pintuPengguna !== null)
        {{-- Dua pintu utama Pengguna: permintaan dan permohonan. --}}
        <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
            @foreach ([
                [
                    'judul' => 'Permintaan Barang',
                    'deskripsi' => 'Ambil barang yang tersedia di gudang.',
                    'ikon' => 'inbox',
                    'warna' => 'sky',
                    'data' => $pintuPengguna['permintaan'],
                    'buat' => route('request.create'),
                    'buatLabel' => 'Buat Permintaan',
                    'daftar' => route('request.index'),
                ],
                [
                    'judul' => 'Permohonan',
                    'deskripsi' => 'Mohon barang baru atau tambah stok barang yang kurang.',
                    'ikon' => 'clipboard-list',
                    'warna' => 'indigo',
                    'data' => $pintuPengguna['permohonan'],
                    'buat' => route('item-applications.create'),
                    'buatLabel' => 'Ajukan Permohonan',
                    'daftar' => route('item-applications.index'),
                ],
            ] as $pintu)
                <section class="{{ $kartu }} flex flex-col">
                    <div class="flex items-start gap-4">
                        <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl text-white {{ $chipUbin[$pintu['warna']] }}">
                            <x-ui.icon :name="$pintu['ikon']" class="size-6" />
                        </span>
                        <div>
                            <h3 class="{{ $judulKartu }}">{{ $pintu['judul'] }}</h3>
                            <p class="text-theme-sm mt-0.5 text-gray-500 dark:text-gray-400">{{ $pintu['deskripsi'] }}</p>
                        </div>
                    </div>

                    <ul class="mt-5 grid grid-cols-3 gap-3">
                        @foreach ($pintu['data']['statistik'] as $statistik)
                            <li>
                                <a href="{{ $statistik['url'] }}"
                                    class="block rounded-xl bg-gray-50 px-3 py-3 transition hover:bg-gray-100 dark:bg-white/[0.03] dark:hover:bg-white/[0.06]">
                                    <span class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $statistik['label'] }}</span>
                                    <span class="mt-1 block text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $statistik['jumlah'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>

                    @if ($pintu['data']['perhatian'] ?? null)
                        <a href="{{ route('request.index', ['tahap' => 'diserahkan']) }}"
                            class="text-theme-sm bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300 mt-4 flex items-center gap-2 rounded-lg px-3 py-2.5">
                            <x-ui.icon name="package" class="size-4" />
                            <span class="flex-1">{{ $pintu['data']['perhatian'] }}</span>
                            <x-ui.icon name="chevron-right" class="size-4" />
                        </a>
                    @endif

                    <div class="mt-auto flex flex-wrap items-center justify-between gap-3 pt-5">
                        <a href="{{ $pintu['buat'] }}"
                            class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                            <x-ui.icon name="plus" class="size-4" />
                            {{ $pintu['buatLabel'] }}
                        </a>
                        <a href="{{ $pintu['daftar'] }}" class="text-theme-sm text-brand-600 hover:text-brand-700 dark:text-brand-400 inline-flex items-center gap-1 font-medium">
                            Lihat semua
                            <x-ui.icon name="arrow-right" class="size-4" />
                        </a>
                    </div>
                </section>
            @endforeach
        </div>
    @else
    {{-- Akses cepat --}}
    <nav aria-label="Akses cepat" class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-[repeat(auto-fill,minmax(12rem,1fr))] md:gap-4">
        @foreach ($aksesCepat as $ubin)
            <a href="{{ $ubin['url'] }}"
                class="hover:shadow-theme-sm flex items-center gap-3 rounded-2xl border border-gray-200 bg-white p-3 transition hover:border-gray-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-700 dark:hover:bg-white/[0.05]">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl text-white {{ $chipUbin[$ubin['warna']] }}">
                    <x-ui.icon :name="$ubin['ikon']" class="size-5" />
                </span>
                <span class="text-theme-sm leading-snug font-medium text-gray-800 dark:text-white/90">{{ $ubin['label'] }}</span>
            </a>
        @endforeach
    </nav>
    @endif

    @if ($dasborUnit !== null)
        {{-- Dasbor Kepala Unit: angka unitnya dan antrean persetujuannya. --}}
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4 md:gap-6">
            @foreach ($dasborUnit['statistik'] as $statistik)
                <a href="{{ $statistik['url'] }}"
                    class="{{ $kartu }} flex items-center gap-4 transition hover:border-gray-300 dark:hover:border-gray-700">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-xl {{ $chipTugas[$statistik['warna']] }}">
                        <x-ui.icon :name="$statistik['ikon']" class="size-5" />
                    </span>
                    <span>
                        <span class="text-theme-sm block text-gray-500 dark:text-gray-400">{{ $statistik['label'] }}</span>
                        <span class="text-title-sm block font-bold text-gray-800 dark:text-white/90">{{ $statistik['jumlah'] }}</span>
                    </span>
                </a>
            @endforeach
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-5">
            <section class="{{ $kartu }} xl:col-span-3">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="{{ $judulKartu }}">Menunggu Persetujuan Anda</h3>
                        <p class="text-theme-sm mt-0.5 text-gray-500 dark:text-gray-400">Permintaan dari {{ $dasborUnit['unit'] }}, yang terlama di atas.</p>
                    </div>
                    <a href="{{ route('request.index', ['status' => 'submitted']) }}" class="text-theme-sm text-brand-600 hover:text-brand-700 dark:text-brand-400 inline-flex items-center gap-1 font-medium">
                        Lihat semua
                        <x-ui.icon name="arrow-right" class="size-4" />
                    </a>
                </div>

                @if ($dasborUnit['antrean']->isEmpty())
                    <div class="flex flex-col items-center py-10 text-center">
                        <span class="bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500 flex size-12 items-center justify-center rounded-full">
                            <x-ui.icon name="check-circle" class="size-6" />
                        </span>
                        <p class="text-theme-sm mt-3 text-gray-500 dark:text-gray-400">Semua permintaan unit Anda sudah diputuskan.</p>
                    </div>
                @else
                    <ul class="mt-4 divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($dasborUnit['antrean'] as $antrean)
                            @php
                                $respondContext = [
                                    'id' => $antrean->request_id,
                                    'number' => $antrean->request_number,
                                    'needsNd' => false,
                                    'preview' => $antrean->documentNumber('ND'),
                                    'sequence' => $antrean->ndSequence(),
                                ];
                                $adaUsulanBarang = $antrean->item->contains(fn ($detail) => $detail->item_proposal_id !== null);
                            @endphp
                            <li class="flex flex-wrap items-center gap-x-4 gap-y-3 py-4">
                                <div class="flex min-w-0 flex-1 items-center gap-3">
                                    @if ($antrean->user)
                                        <x-ui.user-avatar :user="$antrean->user" class="size-10 text-xs" />
                                    @endif
                                    <div class="min-w-0">
                                        <p class="text-theme-sm truncate font-medium text-gray-800 dark:text-white/90">
                                            {{ $antrean->user?->fullname ?? '-' }}
                                            @if ($antrean->type === 'emergency')
                                                <span class="text-theme-xs bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400 ml-1 rounded-full px-2 py-0.5 font-medium">Darurat</span>
                                            @endif
                                        </p>
                                        <p class="text-theme-xs truncate text-gray-500 dark:text-gray-400">
                                            {{ $antrean->ringkasanBarang() }} · {{ $antrean->date?->translatedFormat('d M Y') }}
                                        </p>
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('request.show', $antrean) }}"
                                        class="text-theme-xs rounded-lg px-3 py-1.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                        Detail
                                    </a>
                                    @unless ($adaUsulanBarang)
                                        <button type="button" x-data
                                            @click="$dispatch('open-respond-modal', @js([...$respondContext, 'action' => 'approve', 'label' => 'Setujui']))"
                                            class="text-theme-xs bg-success-500 hover:bg-success-600 rounded-lg px-3 py-1.5 font-medium text-white transition">
                                            Setujui
                                        </button>
                                        <button type="button" x-data
                                            @click="$dispatch('open-respond-modal', @js([...$respondContext, 'action' => 'reject', 'label' => 'Tolak']))"
                                            class="text-theme-xs text-error-600 dark:text-error-500 ring-error-200 dark:ring-error-500/30 hover:bg-error-50 dark:hover:bg-error-500/10 rounded-lg px-3 py-1.5 font-medium ring-1 ring-inset transition">
                                            Tolak
                                        </button>
                                    @endunless
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <div class="space-y-6 xl:col-span-2">
                <section class="{{ $kartu }}">
                    <h3 class="{{ $judulKartu }}">Barang Paling Banyak Diminta</h3>
                    <p class="text-theme-sm mt-0.5 text-gray-500 dark:text-gray-400">Unit Anda, 3 bulan terakhir.</p>

                    @if ($dasborUnit['barangTeratas'] === [])
                        <p class="text-theme-sm py-8 text-center text-gray-500 dark:text-gray-400">Belum ada permintaan dalam 3 bulan terakhir.</p>
                    @else
                        <ul class="mt-4 space-y-3">
                            @foreach ($dasborUnit['barangTeratas'] as $barang)
                                <li>
                                    <div class="text-theme-sm flex items-center justify-between gap-3">
                                        <span class="truncate text-gray-700 dark:text-gray-300">{{ $barang['nama'] }}</span>
                                        <span class="shrink-0 font-medium text-gray-800 tabular-nums dark:text-white/90">{{ $barang['total'] }} {{ $barang['satuan'] }}</span>
                                    </div>
                                    <div class="bg-brand-100 dark:bg-brand-500/20 mt-1.5 h-1.5 overflow-hidden rounded-full">
                                        <div class="bg-brand-500 h-full rounded-full" style="width: {{ $barang['persen'] }}%"></div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section class="{{ $kartu }}">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="{{ $judulKartu }}">Permohonan Unit</h3>
                        <a href="{{ route('item-applications.index') }}" class="text-theme-sm text-brand-600 hover:text-brand-700 dark:text-brand-400 font-medium">Lihat semua</a>
                    </div>
                    <ul class="mt-4 grid grid-cols-3 gap-3">
                        @foreach ($dasborUnit['permohonan'] as $statistik)
                            <li>
                                <a href="{{ $statistik['url'] }}"
                                    class="block rounded-xl bg-gray-50 px-3 py-3 transition hover:bg-gray-100 dark:bg-white/[0.03] dark:hover:bg-white/[0.06]">
                                    <span class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $statistik['label'] }}</span>
                                    <span class="mt-1 block text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $statistik['jumlah'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            </div>
        </div>

        @include('pages.request._respond-modal', ['kembali' => 'dasbor'])
    @else
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-5">
        {{-- Perlu ditindaklanjuti --}}
        <section @class([$kartu, 'xl:col-span-2' => $stok !== null || $pintuPengguna !== null, 'xl:col-span-5' => $stok === null && $pintuPengguna === null])>
            <h3 class="{{ $judulKartu }}">Perlu Ditindaklanjuti</h3>

            @if ($tugas === [])
                <div class="flex flex-col items-center py-10 text-center">
                    <span class="bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500 flex size-12 items-center justify-center rounded-full">
                        <x-ui.icon name="check-circle" class="size-6" />
                    </span>
                    <p class="text-theme-sm mt-3 text-gray-500 dark:text-gray-400">Tidak ada yang perlu ditindaklanjuti.</p>
                </div>
            @else
                <ul class="-mx-2 mt-4 space-y-1">
                    @foreach ($tugas as $baris)
                        <li>
                            <a href="{{ $baris['url'] }}"
                                class="group flex items-center gap-3 rounded-xl px-2 py-2.5 transition hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg {{ $chipTugas[$baris['warna']] }}">
                                    <x-ui.icon :name="$baris['ikon']" class="size-[18px]" />
                                </span>
                                <span class="text-theme-sm flex-1 text-gray-700 dark:text-gray-300">{{ $baris['label'] }}</span>
                                <span class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $baris['jumlah'] }}</span>
                                <x-ui.icon name="chevron-right" class="size-4 text-gray-400 transition group-hover:translate-x-0.5" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Aktivitas terbaru Pengguna: permintaan dan permohonan bercampur. --}}
        @if ($pintuPengguna !== null)
            <section class="{{ $kartu }} xl:col-span-3">
                <h3 class="{{ $judulKartu }}">Aktivitas Terbaru</h3>

                @if ($pintuPengguna['aktivitas'] === [])
                    <p class="text-theme-sm py-10 text-center text-gray-500 dark:text-gray-400">
                        Belum ada permintaan maupun permohonan.
                    </p>
                @else
                    <ul class="-mx-2 mt-4 space-y-1">
                        @foreach ($pintuPengguna['aktivitas'] as $aktivitas)
                            <li>
                                <a href="{{ $aktivitas['url'] }}"
                                    class="group flex items-center gap-3 rounded-xl px-2 py-2.5 transition hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                                    <span @class([
                                        'flex size-9 shrink-0 items-center justify-center rounded-lg',
                                        'bg-sky-50 text-sky-600 dark:bg-sky-500/15 dark:text-sky-400' => $aktivitas['jenis'] === 'Permintaan',
                                        'bg-indigo-50 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-400' => $aktivitas['jenis'] === 'Permohonan',
                                    ])>
                                        <x-ui.icon :name="$aktivitas['ikon']" class="size-[18px]" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="text-theme-sm block truncate font-medium text-gray-800 dark:text-white/90">
                                            {{ $aktivitas['nomor'] }}
                                            <span class="font-normal text-gray-400">· {{ $aktivitas['jenis'] }}</span>
                                        </span>
                                        <span class="text-theme-xs block truncate text-gray-500 dark:text-gray-400">
                                            {{ $aktivitas['barang'] }} · {{ $aktivitas['waktu']?->translatedFormat('d M Y') }}
                                        </span>
                                    </span>
                                    <span class="text-theme-xs inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 font-medium {{ $aktivitas['badge'] }}">
                                        <span class="size-1.5 rounded-full bg-current"></span>
                                        {{ $aktivitas['status'] }}
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        {{-- Kondisi stok --}}
        @if ($stok !== null)
            <section class="{{ $kartu }} xl:col-span-3">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="{{ $judulKartu }}">Kondisi Stok</h3>
                    <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $stok['total'] }} barang aktif</span>
                </div>

                @if ($stok['total'] > 0)
                    <div class="mt-5 flex h-3 w-full gap-0.5 overflow-hidden rounded-full" role="img"
                        aria-label="{{ collect($stok['sebaran'])->map(fn ($s) => $s['status']->label().' '.$s['jumlah'])->join(', ') }}">
                        @foreach ($stok['sebaran'] as $sebaran)
                            @if ($sebaran['jumlah'] > 0)
                                <div class="h-full min-w-1 {{ $warnaStok[$sebaran['status']->value]['isi'] }}" style="width: {{ $sebaran['persen'] }}%"></div>
                            @endif
                        @endforeach
                    </div>
                @endif

                <ul class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach ($stok['sebaran'] as $sebaran)
                        @php $tag = $sebaran['url'] ? 'a' : 'div'; @endphp
                        <li>
                            <{{ $tag }} @if ($sebaran['url']) href="{{ $sebaran['url'] }}" @endif
                                @class(['block rounded-lg px-2 py-1.5', 'transition hover:bg-gray-50 dark:hover:bg-white/[0.03]' => $sebaran['url']])>
                                <span class="text-theme-sm flex items-center gap-2 text-gray-500 dark:text-gray-400">
                                    <span class="size-2.5 rounded-full {{ $warnaStok[$sebaran['status']->value]['isi'] }}"></span>
                                    {{ $sebaran['status']->label() }}
                                </span>
                                <span class="mt-0.5 block text-xl font-semibold text-gray-800 dark:text-white/90">{{ $sebaran['jumlah'] }}</span>
                            </{{ $tag }}>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-5 border-t border-gray-100 pt-5 dark:border-gray-800">
                    <h4 class="text-theme-sm font-medium text-gray-800 dark:text-white/90">Perlu diisi ulang</h4>

                    @if ($stok['kritis']->isEmpty())
                        <p class="text-theme-sm mt-3 text-gray-500 dark:text-gray-400">Semua stok di atas minimum.</p>
                    @else
                        <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($stok['kritis'] as $item)
                                @php
                                    $warna = $warnaStok[$item->stockStatus()->value];
                                    $persen = $item->stock_min > 0 ? min(100, $item->stock_current / $item->stock_min * 100) : 0;
                                    $tag = $stok['bertaut'] ? 'a' : 'div';
                                @endphp
                                <li>
                                    <{{ $tag }} @if ($stok['bertaut']) href="{{ route('items.show', $item) }}" @endif
                                        @class([
                                            'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2 py-3 sm:grid-cols-[minmax(0,1fr)_9rem_6.5rem]',
                                            '-mx-2 rounded-lg px-2 transition hover:bg-gray-50 dark:hover:bg-white/[0.03]' => $stok['bertaut'],
                                        ])>
                                        <span class="min-w-0">
                                            <span class="text-theme-sm block truncate font-medium text-gray-800 dark:text-white/90">{{ $item->item_name }}</span>
                                            <span class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $item->item_code }}</span>
                                        </span>
                                        <span class="order-last col-span-2 block h-1.5 overflow-hidden rounded-full sm:order-none sm:col-span-1 {{ $warna['lintasan'] }}">
                                            <span class="block h-full rounded-full {{ $warna['isi'] }}" style="width: {{ $persen }}%"></span>
                                        </span>
                                        <span class="text-theme-sm text-right whitespace-nowrap text-gray-700 tabular-nums dark:text-gray-300">
                                            {{ $item->stock_current }} / {{ $item->stock_min }}
                                            <span class="text-gray-500 dark:text-gray-400">{{ $item->unit->label() }}</span>
                                        </span>
                                    </{{ $tag }}>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>
        @endif
    </div>

    @endif

    {{-- Alur permintaan: cakupannya mengikuti daftar permintaan, jadi
         Pengguna hanya melihat miliknya dan Kepala Unit hanya unitnya. --}}
    @include('pages.dashboard._alur', ['judul' => 'Alur Permintaan', 'tahapan' => $alurPermintaan])

    {{-- Alur pengadaan --}}
    @if ($alur !== null)
        @include('pages.dashboard._alur', ['judul' => 'Alur Pengadaan', 'tahapan' => $alur])
    @endif

    {{-- Grafik --}}
    @if ($grafikMutasi !== null || $grafikNilai !== null)
        <div @class(['mt-6 grid grid-cols-1 gap-6', 'xl:grid-cols-2' => $grafikMutasi !== null && $grafikNilai !== null])>
            @if ($grafikMutasi !== null)
                <section class="{{ $kartu }}">
                    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                        <div>
                            <h3 class="{{ $judulKartu }}">Mutasi Stok</h3>
                            <p class="text-theme-sm mt-0.5 text-gray-500 dark:text-gray-400">{{ $grafikMutasi['total'] }} transaksi, 6 bulan terakhir</p>
                        </div>
                        <ul class="text-theme-sm flex items-center gap-4 text-gray-600 dark:text-gray-300">
                            <li class="flex items-center gap-1.5"><span class="bg-brand-500 size-2.5 rounded-sm"></span>Masuk</li>
                            <li class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-[#eb6834] dark:bg-[#d95926]"></span>Keluar</li>
                        </ul>
                    </div>

                    @if ($grafikMutasi['total'] === 0)
                        <p class="text-theme-sm py-20 text-center text-gray-500 dark:text-gray-400">Belum ada mutasi.</p>
                    @else
                        <div class="-ml-3 mt-2" data-grafik-dasbor="mutasi" data-grafik="{{ json_encode($grafikMutasi) }}"></div>
                        <table class="sr-only">
                            <caption>Jumlah transaksi mutasi stok per bulan</caption>
                            <thead><tr><th scope="col">Bulan</th><th scope="col">Masuk</th><th scope="col">Keluar</th></tr></thead>
                            <tbody>
                                @foreach ($grafikMutasi['bulanPanjang'] as $i => $bulan)
                                    <tr><th scope="row">{{ $bulan }}</th><td>{{ $grafikMutasi['masuk'][$i] }}</td><td>{{ $grafikMutasi['keluar'][$i] }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </section>
            @endif

            @if ($grafikNilai !== null)
                <section class="{{ $kartu }}">
                    <h3 class="{{ $judulKartu }}">Nilai Pengadaan Diterima</h3>
                    <p class="text-theme-sm mt-0.5 text-gray-500 dark:text-gray-400">{{ $rupiah($grafikNilai['total']) }}, 6 bulan terakhir</p>

                    @if ($grafikNilai['total'] === 0)
                        <p class="text-theme-sm py-20 text-center text-gray-500 dark:text-gray-400">Belum ada barang diterima.</p>
                    @else
                        <div class="-ml-3 mt-2" data-grafik-dasbor="nilai" data-grafik="{{ json_encode($grafikNilai) }}"></div>
                        <table class="sr-only">
                            <caption>Nilai pengadaan diterima per bulan</caption>
                            <thead><tr><th scope="col">Bulan</th><th scope="col">Nilai</th></tr></thead>
                            <tbody>
                                @foreach ($grafikNilai['bulanPanjang'] as $i => $bulan)
                                    <tr><th scope="row">{{ $bulan }}</th><td>{{ $rupiah($grafikNilai['nilai'][$i]) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </section>
            @endif
        </div>
    @endif
@endsection
