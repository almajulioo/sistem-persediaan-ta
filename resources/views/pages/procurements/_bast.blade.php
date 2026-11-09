{{-- Kartu BAST digital (ADR 0051): satu tempat untuk seluruh langkah serah
     terima, selebar penuh di atas halaman supaya tidak tersembunyi.

     1. Hasil Pemeriksaan — Pengelola Gudang
     2. Nomor BAST        — Pejabat Pengadaan atau Pengelola Gudang
     3. TTE Pihak Pertama — Pejabat Pengadaan (Pelaksana Belanja)
     4. TTE Pihak Kedua   — Pengelola Gudang (Petugas Persediaan)

     TTE ketiga dan keempat urutannya bebas; yang terakhir memasukkan stok.
     Tombol "Lihat BAST" dan "Unduh BAST Kosong" selalu tampil, di tahap mana
     pun. Setiap pengguna hanya melihat tombol untuk langkah miliknya; peran lain
     melihat siapa yang sedang ditunggu.

     Dialog-dialognya memakai keadaan Alpine milik grid halaman detail
     (`tteTerbuka`, `nomorBastTerbuka`). --}}
@php
    $receipt = $procurement->receipt;
    $lengkap = $procurement->isDiterima();
    $diperiksa = $procurement->hasilPemeriksaanLengkap();
    $bernomor = filled($receipt?->bast_number);
    $pihakSaya = auth()->user()->can('tte-bast') ? \App\Enums\PihakBast::dariPeran(auth()->user()->role) : null;
    $ttd = collect(\App\Enums\PihakBast::cases())->mapWithKeys(fn (\App\Enums\PihakBast $p) => [$p->value => $receipt?->tteAktif($p)]);
    $namaTte = $ttd->filter()->map->signer_name->values();
    $siapTte = $diperiksa && $bernomor && ! $lengkap;
    $ttdTerakhir = $ttd->filter()->sortBy('signed_at')->last();

    // Ringkasan untuk dialog TTE: yang benar-benar datang (ADR 0017).
    $jenisDiterima = $procurement->details->filter(fn ($d) => ($d->amount_checked ?? 0) > 0)->count();
    $nilaiDiperiksa = $procurement->details->sum(fn ($d) => ($d->amount_checked ?? 0) * $d->unit_price);
    $tteTerakhirSaya = $pihakSaya !== null && $ttd->filter()->count() === 1 && $ttd[$pihakSaya->value] === null;

    // Langkah aktif: yang pertama belum selesai.
    $langkahAktif = match (true) {
        $lengkap => 0,
        ! $diperiksa => 1,
        ! $bernomor => 2,
        default => 3,
    };

    $kelasTombol = 'bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-white transition';
    $kelasTombolSekunder = 'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]';
    $kelasTautan = 'text-theme-sm text-brand-500 hover:text-brand-600 dark:text-brand-400 font-medium';
@endphp

<div class="col-span-12 rounded-2xl border bg-white dark:bg-white/[0.03] {{ $lengkap ? 'border-success-200 dark:border-success-500/30' : 'border-brand-200 dark:border-brand-500/30' }}">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.icon name="file-check" class="size-5 text-gray-500 dark:text-gray-400" />
            <h3 class="text-base font-medium text-gray-800 dark:text-white/90">Berita Acara Serah Terima</h3>
            <span @class([
                'text-theme-xs rounded-full px-2.5 py-1 font-medium',
                'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500' => $lengkap,
                'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400' => ! $lengkap,
            ])>{{ \App\Support\BastPengadaan::tahap($procurement) }}</span>
            @if ($bernomor)
                <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $receipt->bast_number }}</span>
            @endif
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('procurements.bast', $procurement) }}" target="_blank" rel="noopener" class="{{ $kelasTombolSekunder }}">
                <x-ui.icon name="eye" class="size-4" />
                Lihat BAST
            </a>
            <a href="{{ route('procurements.bast-blank', $procurement) }}" target="_blank" rel="noopener" class="{{ $kelasTombolSekunder }}">
                <x-ui.icon name="download" class="size-4" />
                Unduh BAST Kosong
            </a>
        </div>
    </div>

    <ol class="grid grid-cols-1 divide-y divide-gray-100 md:grid-cols-4 md:divide-x md:divide-y-0 dark:divide-gray-800">
        {{-- 1. Hasil pemeriksaan --}}
        <li class="p-5 sm:px-6">
            @include('pages.procurements._bast-langkah', [
                'nomor' => 1,
                'judul' => 'Hasil Pemeriksaan',
                'peran' => 'Pengelola Gudang',
                'selesai' => $diperiksa,
                'aktif' => $langkahAktif === 1,
            ])

            <div class="mt-3 pl-11">
                @if ($diperiksa)
                    <p class="text-theme-sm text-gray-800 dark:text-white/90">{{ $receipt->received_at->translatedFormat('d F Y') }}</p>
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $receipt->receivedBy?->fullname ?? '—' }}</p>
                    @if (! $lengkap)
                        @can('kelola-gudang')
                            <a href="{{ route('procurement-receipts.create', $procurement) }}" class="{{ $kelasTautan }} mt-2 inline-block">Ubah</a>
                        @endcan
                    @endif
                @else
                    @can('kelola-gudang')
                        <a href="{{ route('procurement-receipts.create', $procurement) }}" class="{{ $kelasTombol }}">
                            <x-ui.icon name="clipboard-check" class="size-4" />
                            {{ $receipt ? 'Lengkapi Pemeriksaan' : 'Catat Hasil Pemeriksaan' }}
                        </a>
                    @else
                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">Menunggu Pengelola Gudang</p>
                    @endcan
                @endif
            </div>
        </li>

        {{-- 2. Nomor BAST --}}
        <li class="p-5 sm:px-6">
            @include('pages.procurements._bast-langkah', [
                'nomor' => 2,
                'judul' => 'Nomor BAST',
                'peran' => 'Pejabat Pengadaan / Pengelola Gudang',
                'selesai' => $bernomor,
                'aktif' => $langkahAktif === 2,
            ])

            <div class="mt-3 pl-11">
                @if ($bernomor)
                    <p class="text-theme-sm break-all text-gray-800 dark:text-white/90">{{ $receipt->bast_number }}</p>
                    @if (! $lengkap)
                        @can('isi-nomor-bast')
                            <button type="button" @click="nomorBastTerbuka = true" class="{{ $kelasTautan }} mt-2">Ubah</button>
                        @endcan
                    @endif
                @elseif ($receipt === null)
                    <p class="text-theme-sm text-gray-400 dark:text-gray-500">Setelah hasil pemeriksaan</p>
                @else
                    @can('isi-nomor-bast')
                        <button type="button" @click="nomorBastTerbuka = true" class="{{ $kelasTombol }}">
                            <x-ui.icon name="hash" class="size-4" />
                            Isi Nomor BAST
                        </button>
                    @else
                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">Menunggu nomor BAST</p>
                    @endcan
                @endif
            </div>
        </li>

        {{-- 3–4. TTE kedua pihak --}}
        @foreach (\App\Enums\PihakBast::cases() as $pihak)
            @php($tte = $ttd[$pihak->value])
            <li class="p-5 sm:px-6">
                @include('pages.procurements._bast-langkah', [
                    'nomor' => $loop->iteration + 2,
                    'judul' => "TTE {$pihak->label()}",
                    'peran' => $pihak->jabatanTtd(),
                    'selesai' => $tte !== null,
                    'aktif' => $langkahAktif === 3 && $tte === null,
                ])

                <div class="mt-3 pl-11">
                    @if ($tte)
                        <p class="text-theme-sm flex items-center gap-1.5 text-gray-800 dark:text-white/90">
                            <x-ui.icon name="qr-code" class="size-4 text-success-600 dark:text-success-500" />
                            {{ $tte->signer_name }}
                        </p>
                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $tte->signed_at->translatedFormat('d M Y H:i') }}</p>
                    @elseif ($pihakSaya === $pihak && ! $lengkap)
                        <button type="button" @click="tteTerbuka = true" @disabled(! $siapTte)
                            class="{{ $kelasTombol }} disabled:cursor-not-allowed disabled:opacity-50">
                            <x-ui.icon name="pen-line" class="size-4" />
                            Tandatangani
                        </button>
                        @unless ($siapTte)
                            <p class="text-theme-xs mt-2 text-gray-400 dark:text-gray-500">
                                {{ $diperiksa ? 'Setelah nomor BAST diisi' : 'Setelah hasil pemeriksaan' }}
                            </p>
                        @endunless
                    @else
                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">Menunggu {{ $pihak->peran()->label() }}</p>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>

    @if ($lengkap)
        <div class="bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400 text-theme-sm flex flex-wrap items-center gap-2 rounded-b-2xl px-5 py-3 sm:px-6">
            <x-ui.icon name="check-circle" class="size-4" />
            Stok sudah masuk &middot; {{ $ttdTerakhir?->signed_at->translatedFormat('d F Y H:i') }}
            @if ($procurement->diterimaSebagian())
                <span class="text-warning-700 dark:text-warning-400">&middot; sebagian barang kurang dari pesanan</span>
            @endif
        </div>
    @endif
</div>

{{-- Dialog TTE. Pihaknya ditentukan server dari peran; formulir ini tidak
     membawa isian apa pun selain token CSRF. --}}
@if ($pihakSaya !== null && $siapTte && $ttd[$pihakSaya->value] === null)
    <div x-show="tteTerbuka" x-cloak @keydown.escape.window="tteTerbuka = false"
        class="fixed inset-0 z-99999 flex items-center justify-center p-5">
        <div @click="tteTerbuka = false" class="fixed inset-0 bg-gray-900/50"></div>

        <div @click.stop class="relative w-full max-w-lg rounded-2xl bg-white p-6 dark:bg-gray-900">
            <h3 class="text-theme-xl font-semibold text-gray-800 dark:text-white/90">
                Tandatangani BAST sebagai {{ $pihakSaya->label() }}?
            </h3>

            <dl class="mt-5 space-y-3">
                @foreach ([
                    'Nomor BAST' => $receipt->bast_number,
                    'Tanggal Diterima' => $receipt->received_at->translatedFormat('d F Y'),
                    'Barang Diterima' => $jenisDiterima.' jenis',
                    'Nilai' => 'Rp'.number_format($nilaiDiperiksa, 0, ',', '.'),
                ] as $label => $value)
                    <div class="flex items-start justify-between gap-4">
                        <dt class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="text-theme-sm text-right font-medium break-all text-gray-800 dark:text-white/90">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <a href="{{ route('procurements.bast', $procurement) }}" target="_blank" rel="noopener"
                class="{{ $kelasTautan }} mt-4 inline-flex items-center gap-1.5">
                <x-ui.icon name="eye" class="size-4" />
                Periksa BAST
            </a>

            @if ($tteTerakhirSaya)
                <p class="text-theme-xs bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400 mt-4 rounded-lg p-3">
                    Setelah ditandatangani, barang masuk ke stok dan BAST tidak dapat diubah lagi.
                </p>
            @endif

            <x-form.sekali-kirim>
                <form method="POST" action="{{ route('procurements.bast-sign', $procurement) }}" class="mt-6 flex justify-end gap-3">
                    @csrf
                    <button type="button" @click="tteTerbuka = false" class="{{ $kelasTombolSekunder }} py-2.5">
                        Tutup
                    </button>
                    <button type="submit" :disabled="terkirim" class="{{ $kelasTombol }} py-2.5 disabled:opacity-50">
                        Ya, tandatangani
                    </button>
                </form>
            </x-form.sekali-kirim>
        </div>
    </div>
@endif

{{-- Dialog nomor BAST. Tanpa saran nomor — nomornya dari agenda kantor
     (ADR 0042). Mengubah nomor yang sudah ditandatangani mencabut TTE-nya. --}}
@if (! $lengkap && $receipt !== null)
    @can('isi-nomor-bast')
        <div x-show="nomorBastTerbuka" x-cloak @keydown.escape.window="nomorBastTerbuka = false"
            class="fixed inset-0 z-99999 flex items-center justify-center p-5">
            <div @click="nomorBastTerbuka = false" class="fixed inset-0 bg-gray-900/50"></div>

            <div @click.stop class="relative w-full max-w-lg rounded-2xl bg-white p-6 dark:bg-gray-900">
                <h3 class="text-theme-xl font-semibold text-gray-800 dark:text-white/90">Nomor BAST</h3>

                @if ($namaTte->isNotEmpty())
                    <p class="text-theme-xs bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400 mt-4 rounded-lg p-3">
                        TTE {{ $namaTte->implode(' dan ') }} akan dibatalkan dan perlu dibubuhkan ulang.
                    </p>
                @endif

                <form method="POST" action="{{ route('procurements.bast-number', $procurement) }}" class="mt-5">
                    @csrf
                    @method('PATCH')

                    <x-form.text-field name="bast_number" label="Nomor BAST" required
                        :value="$receipt->bast_number"
                        placeholder="mis. 001/UP_TUP/445237/{{ $receipt->received_at->year }}" />

                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" @click="nomorBastTerbuka = false" class="{{ $kelasTombolSekunder }} py-2.5">
                            Tutup
                        </button>
                        <button type="submit" class="{{ $kelasTombol }} py-2.5">
                            Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endcan
@endif
