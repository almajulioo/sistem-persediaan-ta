@extends('layouts.app')

@php
    $canDecide = $application->bisaDiputuskanOleh(auth()->user());
    $tahapAkhir = $application->status === \App\Enums\ItemApplicationStatus::DitinjauGudang;
@endphp

@section('content')
    <x-modul.judul :modul="\App\Enums\ModulPengajuan::Permohonan" :judul="$application->application_number">
        <x-slot:bawah>
            <nav class="mt-1">
                <ol class="flex items-center gap-1.5 text-sm">
                    <li>
                        <a href="{{ route('item-applications.index') }}" class="text-gray-500 hover:text-violet-600 dark:text-gray-400">Daftar Permohonan</a>
                    </li>
                    <li class="text-gray-400">/</li>
                    <li class="text-gray-800 dark:text-white/90">{{ $application->application_number }}</li>
                </ol>
            </nav>
        </x-slot:bawah>

            <x-ui.pill :warna="$application->status->warna()" :ikon="$application->status->icon()">
                {{ $application->status->label() }}
            </x-ui.pill>

            @if ($hasNotaDinas)
                <a href="{{ route('item-applications.nota-dinas', $application) }}" target="_blank" rel="noopener"
                    class="rounded-lg px-4 py-2.5 text-sm font-medium {{ \App\Enums\ModulPengajuan::Permohonan->tombolClass() }}">
                    Unduh Nota Dinas
                </a>
            @endif

            @if ($canDecide)
                <button type="button" x-data @click="$dispatch('buka-setujui-permohonan')"
                    class="bg-success-500 hover:bg-success-600 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                    Setujui &amp; Teruskan
                </button>
                <button type="button" x-data @click="$dispatch('buka-tolak-permohonan')"
                    class="text-error-500 hover:bg-error-50 ring-error-300 dark:ring-error-500/30 dark:hover:bg-error-500/10 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium ring-1 ring-inset transition">
                    Tolak
                </button>
            @endif
    </x-modul.judul>

    <x-ui.flash />

    <div class="grid grid-cols-12 gap-4 md:gap-6">

        <div class="col-span-12 space-y-4 md:space-y-6 xl:col-span-5">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                <dl class="space-y-3">
                    @foreach ([
                        'Pemohon' => $application->user?->fullname ?? '—',
                        'Unit Kerja' => $application->user?->unit?->label() ?? '—',
                        'Tanggal' => $application->date?->translatedFormat('d F Y') ?? '—',
                        'Nomor Nota Dinas' => $application->nd_number ?? '—',
                        'Rincian' => ucfirst($application->ringkasan()),
                    ] as $label => $value)
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="text-theme-sm text-right font-medium text-gray-800 dark:text-white/90">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">Keperluan</p>
                    <p class="text-theme-sm mt-1 whitespace-pre-line text-gray-800 dark:text-white/90">{{ $application->purpose }}</p>
                </div>
            </div>

            {{-- Permohonan dari sebelum alur bertahap tidak punya Nota Dinas,
                 dan keputusannya tidak melewati tahap-tahap ini. --}}
            @if ($application->nd_number)
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                    <h3 class="text-base font-medium text-gray-800 dark:text-white/90">Tahapan Persetujuan</h3>
                    <ol class="mt-4 space-y-4">
                        @foreach ($application->tahapan() as $tahap)
                            @php
                                [$warna, $ikon, $keterangan] = match ($tahap['keadaan']) {
                                    'selesai' => ['success', 'check-circle', 'Disetujui '.($tahap['oleh']?->fullname ?? '')],
                                    'ditolak' => ['error', 'x-circle', 'Ditolak '.($tahap['oleh']?->fullname ?? '')],
                                    'menunggu' => ['warning', 'clock', 'Menunggu keputusan'],
                                    default => ['gray', 'clock', 'Belum sampai tahap ini'],
                                };
                            @endphp
                            <li class="flex items-start gap-3">
                                <span class="flex size-8 shrink-0 items-center justify-center rounded-lg {{ \App\Support\Warna::lembut($warna) }}">
                                    <x-ui.icon :name="$ikon" class="size-4" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $tahap['label'] }}</p>
                                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $keterangan }}
                                        @if ($tahap['pada'])
                                            · {{ $tahap['pada']->translatedFormat('d F Y, H:i') }}
                                        @endif
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif

            @unless ($application->isMenunggu())
                <div @class([
                    'rounded-2xl border bg-white p-5 dark:bg-white/[0.03] md:p-6',
                    'border-error-200 dark:border-error-500/30' => $application->isDitolak(),
                    'border-success-200 dark:border-success-500/30' => $application->isDisetujui(),
                ])>
                    <h3 class="flex items-center gap-3 text-base font-medium text-gray-800 dark:text-white/90">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg {{ \App\Support\Warna::lembut($application->status->warna()) }}">
                            <x-ui.icon :name="$application->status->icon()" class="size-5" />
                        </span>
                        {{ $application->status->label() }}
                    </h3>
                    <dl class="mt-3 space-y-3">
                        @foreach ([
                            'Diputuskan Oleh' => $application->decidedBy?->fullname ?? '—',
                            'Tanggal' => $application->decided_at?->translatedFormat('d F Y, H:i') ?? '—',
                        ] as $label => $value)
                            <div class="flex items-start justify-between gap-4">
                                <dt class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                <dd class="text-theme-sm text-right font-medium text-gray-800 dark:text-white/90">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    @if ($application->decision_note)
                        <p class="text-theme-xs mt-4 border-t border-gray-100 pt-4 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                            {{ $application->decision_note }}
                        </p>
                    @endif
                </div>
            @endunless
        </div>

        <div class="col-span-12 xl:col-span-7">
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-800">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Barang yang Dimohon</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px]">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400">Barang</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400">Jumlah</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400">Tindak Lanjut</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($application->details as $detail)
                                <tr class="border-b border-gray-100 align-top last:border-0 dark:border-gray-800">
                                    <td class="border-l-[3px] px-6 py-4 {{ \App\Support\Warna::garis($detail->type->warna()) }}">
                                        <x-ui.pill :warna="$detail->type->warna()" :ikon="$detail->type->icon()" class="mb-1">
                                            {{ $detail->type->label() }}
                                        </x-ui.pill>
                                        <p class="text-sm font-medium text-gray-800 dark:text-white/90">
                                            @if ($detail->item)
                                                {{ $detail->item->item_code }} — {{ $detail->item->item_name }}
                                            @else
                                                {{ $detail->namaBarang() }}
                                            @endif
                                        </p>
                                        @if ($detail->isTambahStok() && $detail->item)
                                            <p class="text-theme-xs text-gray-500 dark:text-gray-400">Stok saat ini: {{ number_format($detail->item->stock_current, 0, ',', '.') }}</p>
                                        @endif
                                        @if ($detail->note)
                                            <p class="text-theme-xs mt-1 whitespace-pre-line text-gray-500 dark:text-gray-400">{{ $detail->note }}</p>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-right text-sm text-gray-700 dark:text-gray-300">
                                        {{ number_format($detail->quantity, 0, ',', '.') }} {{ $detail->satuan()?->label() }}
                                    </td>
                                    <td class="text-theme-sm px-6 py-4 text-gray-600 dark:text-gray-300">
                                        @if (! $application->isDisetujui())
                                            —
                                        @elseif ($detail->isTambahStok())
                                            @if ($detail->procurementRequest === null)
                                                Sudah dikeluarkan dari draf usulan pengadaan
                                            @elseif ($detail->procurementRequest->isDraf())
                                                @can('kelola-gudang')
                                                    <a href="{{ route('procurement-requests.draft.edit') }}" class="text-brand-600 hover:text-brand-700 dark:text-brand-400">Draf usulan pengadaan</a>
                                                @else
                                                    Masuk draf usulan pengadaan
                                                @endcan
                                            @else
                                                @can('akses-pengadaan')
                                                    <a href="{{ route('procurement-requests.show', $detail->procurementRequest) }}" class="text-brand-600 hover:text-brand-700 dark:text-brand-400">{{ $detail->procurementRequest->judul() }}</a>
                                                @else
                                                    {{ $detail->procurementRequest->judul() }}
                                                @endcan
                                                · {{ $detail->procurementRequest->status->label() }}
                                            @endif
                                        @elseif ($detail->itemProposal)
                                            {{-- Usulan barang tidak punya halaman sendiri: didaftarkan
                                                 atau ditolak dari sini (ADR 0053). Menyetujui berarti
                                                 mendaftarkan barangnya lewat formulir barang yang sudah
                                                 terisi, bukan aksi tersendiri (ADR 0032). --}}
                                            {{-- Bentuk blok, bukan bentuk sebaris: berkas ini juga
                                                 memuat blok php, dan Blade menelan isi di antara
                                                 bentuk sebaris dan penutup blok pertama sesudahnya. --}}
                                            @php
                                                $usulan = $detail->itemProposal;
                                            @endphp
                                            Usulan barang baru · {{ $usulan->status->label() }}
                                            @if ($usulan->item)
                                                <span class="block text-theme-xs text-gray-500 dark:text-gray-400">
                                                    Terdaftar sebagai
                                                    @can('akses-gudang')
                                                        <a href="{{ route('items.show', $usulan->item) }}" class="text-brand-600 hover:text-brand-700 dark:text-brand-400">{{ $usulan->item->item_code }}</a>
                                                    @else
                                                        {{ $usulan->item->item_code }}
                                                    @endcan
                                                </span>
                                            @endif
                                            @if ($usulan->isDitolak() && $usulan->decision_note)
                                                <span class="block text-theme-xs whitespace-pre-line text-gray-500 dark:text-gray-400">{{ $usulan->decision_note }}</span>
                                            @endif
                                            @if ($usulan->isDiajukan())
                                                @can('kelola-gudang')
                                                    <div class="mt-2 flex flex-wrap gap-2">
                                                        <a href="{{ route('items.create', ['item_proposal_id' => $usulan->item_proposal_id]) }}"
                                                            class="bg-brand-500 hover:bg-brand-600 text-theme-xs inline-flex items-center rounded-lg px-3 py-1.5 font-medium text-white transition">
                                                            Daftarkan
                                                        </a>
                                                        <button type="button" x-data
                                                            @click="$dispatch('buka-tolak-usulan-barang', @js(['id' => $usulan->item_proposal_id, 'nama' => $usulan->item_name, 'aksi' => route('item-proposals.reject', $usulan)]))"
                                                            class="text-error-500 hover:bg-error-50 ring-error-300 dark:ring-error-500/30 dark:hover:bg-error-500/10 text-theme-xs inline-flex items-center rounded-lg px-3 py-1.5 font-medium ring-1 ring-inset transition">
                                                            Tolak
                                                        </button>
                                                    </div>
                                                @endcan
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    @if ($canDecide)
        @php
            $tambahStok = $application->details->filter->isTambahStok();
            $barangBaru = $application->details->filter->isBarangBaru();
        @endphp

        {{-- Konfirmasi persetujuan: menjelaskan ke mana tiap barang diteruskan. --}}
        <x-ui.modal @buka-setujui-permohonan.window="open = true" :isOpen="false" class="max-w-[560px]">
            <form method="POST" action="{{ route('item-applications.approve', $application) }}" class="p-6 sm:p-8">
                @csrf
                @method('PATCH')

                <p class="text-sm font-medium text-success-600">Setujui permohonan {{ $application->application_number }}</p>
                <h3 class="mt-2 text-xl font-semibold text-gray-800 dark:text-white/90">Teruskan permohonan ini?</h3>
                <p class="mt-3 text-sm leading-6 text-gray-500 dark:text-gray-400">
                    @if ($tahapAkhir)
                        Ini keputusan akhir. Setelah disetujui, permohonan tidak bisa dibatalkan dari sini. Barangnya diteruskan sebagai berikut.
                    @elseif ($application->isDiajukan())
                        Permohonan diteruskan ke Pengelola Gudang untuk ditinjau.
                    @else
                        Permohonan diteruskan ke Kasubbag Umum dan TI untuk keputusan akhir. Barangnya belum masuk pengadaan.
                    @endif
                </p>

                @if ($tahapAkhir)
                <div class="mt-5 space-y-3">
                    @if ($tambahStok->isNotEmpty())
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                            <div class="flex items-start gap-3">
                                <span class="bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400 flex size-9 shrink-0 items-center justify-center rounded-lg">
                                    <x-ui.icon name="file-plus" class="size-5" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $tambahStok->count() }} barang masuk draf usulan pengadaan</p>
                                    <p class="text-theme-xs mt-0.5 text-gray-500 dark:text-gray-400">Jumlahnya ditambahkan ke draf; Pengelola Gudang masih bisa menyesuaikannya sebelum diajukan.</p>
                                    <ul class="text-theme-xs mt-2 space-y-0.5 text-gray-600 dark:text-gray-300">
                                        @foreach ($tambahStok as $detail)
                                            <li>{{ $detail->namaBarang() }} — {{ number_format($detail->quantity, 0, ',', '.') }} {{ $detail->satuan()?->label() }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </div>
                    @endif

                    @if ($barangBaru->isNotEmpty())
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                            <div class="flex items-start gap-3">
                                <span class="bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400 flex size-9 shrink-0 items-center justify-center rounded-lg">
                                    <x-ui.icon name="package-plus" class="size-5" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $barangBaru->count() }} usulan barang baru dibuat</p>
                                    <p class="text-theme-xs mt-0.5 text-gray-500 dark:text-gray-400">Didaftarkan ke master barang atau ditolak dari halaman ini.</p>
                                    <ul class="text-theme-xs mt-2 space-y-0.5 text-gray-600 dark:text-gray-300">
                                        @foreach ($barangBaru as $detail)
                                            <li>{{ $detail->namaBarang() }} — {{ number_format($detail->quantity, 0, ',', '.') }} {{ $detail->satuan()?->label() }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
                @endif

                @if ($pemberitahuanQr = \App\Support\PemberitahuanQr::permohonan($application))
                    <div x-data="{ qr: @js($pemberitahuanQr) }">
                        @include('pages.request._qr-notice', ['sumber' => 'qr'])
                    </div>
                @endif

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" @click="open = false"
                        class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                        Batal
                    </button>
                    <button type="submit" class="bg-success-500 hover:bg-success-600 rounded-lg px-4 py-2.5 text-sm font-medium text-white">
                        Ya, Setujui &amp; Teruskan
                    </button>
                </div>
            </form>
        </x-ui.modal>

        {{-- Penolakan; terbuka sendiri bila validasinya gagal supaya pesan
             galatnya tidak tersembunyi. --}}
        <x-ui.modal @buka-tolak-permohonan.window="open = true" :isOpen="$errors->has('decision_note')" class="max-w-[520px]">
            <form method="POST" action="{{ route('item-applications.reject', $application) }}" class="p-6 sm:p-8">
                @csrf
                @method('PATCH')

                <p class="text-sm font-medium text-error-500">Tolak permohonan {{ $application->application_number }}</p>
                <h3 class="mt-2 text-xl font-semibold text-gray-800 dark:text-white/90">Tolak permohonan ini?</h3>
                <p class="mt-3 text-sm leading-6 text-gray-500 dark:text-gray-400">
                    Permohonan tidak akan diteruskan ke pengadaan. Alasannya dikirim ke pemohon.
                </p>

                <label for="decision_note" class="mt-5 mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Alasan Penolakan<span class="text-error-500">*</span>
                </label>
                <textarea id="decision_note" name="decision_note" rows="3" required maxlength="500"
                    placeholder="mis. stok masih mencukupi untuk tiga bulan ke depan"
                    class="dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:text-white/90 dark:placeholder:text-white/30">{{ old('decision_note') }}</textarea>
                @error('decision_note')
                    <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
                @enderror

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" @click="open = false"
                        class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                        Batal
                    </button>
                    <button type="submit" class="bg-error-500 hover:bg-error-600 rounded-lg px-4 py-2.5 text-sm font-medium text-white">
                        Ya, Tolak
                    </button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    @php
        $usulanMenunggu = $application->details->pluck('itemProposal')->filter(fn ($usulan) => $usulan?->isDiajukan());
    @endphp

    {{-- Penolakan usulan barang baru (ADR 0053). Satu modal untuk semua
         baris; barisnya dikirim lewat event. Hanya ada setelah permohonan
         disetujui, jadi tidak pernah bersamaan dengan modal tolak permohonan
         di atas walau sama-sama memakai `decision_note`. Terbuka sendiri bila
         validasinya gagal supaya pesan galatnya tidak tersembunyi. --}}
    @if ($usulanMenunggu->isNotEmpty())
        @can('kelola-gudang')
            @php
                $usulanGagal = $errors->has('decision_note')
                    ? $usulanMenunggu->firstWhere('item_proposal_id', old('item_proposal_id'))
                    : null;
                $usulanAwal = $usulanGagal === null
                    ? ['id' => '', 'nama' => '', 'aksi' => '']
                    : ['id' => $usulanGagal->item_proposal_id, 'nama' => $usulanGagal->item_name, 'aksi' => route('item-proposals.reject', $usulanGagal)];
            @endphp

            <div x-data="{ usulan: @js($usulanAwal) }">
                <x-ui.modal @buka-tolak-usulan-barang.window="usulan = $event.detail; open = true" :isOpen="$usulanGagal !== null" class="max-w-[520px]">
                    <form method="POST" :action="usulan.aksi" class="p-6 sm:p-8">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="item_proposal_id" :value="usulan.id">

                        <p class="text-sm font-medium text-error-500">Tolak usulan <span x-text="usulan.nama"></span></p>
                        <h3 class="mt-2 text-xl font-semibold text-gray-800 dark:text-white/90">Tolak usulan barang ini?</h3>
                        <p class="mt-3 text-sm leading-6 text-gray-500 dark:text-gray-400">
                            Barangnya tidak akan didaftarkan ke master barang. Alasannya dikirim ke pemohon.
                        </p>

                        <label for="decision_note_usulan" class="mt-5 mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Alasan Penolakan<span class="text-error-500">*</span>
                        </label>
                        <textarea id="decision_note_usulan" name="decision_note" rows="3" required maxlength="500"
                            placeholder="mis. barangnya sudah terdaftar sebagai ATK-014 Spidol Permanen Emas"
                            class="dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:text-white/90 dark:placeholder:text-white/30">{{ $usulanGagal ? old('decision_note') : '' }}</textarea>
                        @if ($usulanGagal)
                            @error('decision_note')
                                <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
                            @enderror
                        @endif

                        <div class="mt-6 flex justify-end gap-3">
                            <button type="button" @click="open = false"
                                class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                                Batal
                            </button>
                            <button type="submit" class="bg-error-500 hover:bg-error-600 rounded-lg px-4 py-2.5 text-sm font-medium text-white">
                                Ya, Tolak
                            </button>
                        </div>
                    </form>
                </x-ui.modal>
            </div>
        @endcan
    @endif
@endsection
