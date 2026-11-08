{{--
    Sumber satu barang usulan pengadaan beserta alasannya (ADR 0044):
    nomor permohonan yang menyumbang barang itu, dan/atau keterangan yang
    diketik Pengelola Gudang untuk barang manual.

    $detail     : App\Models\ProcurementRequestDetail
    $permohonan : baris ItemApplicationDetail untuk barang ini, dari
                  ProcurementRequest::permohonanPerBarang()

    Keterangan ditampilkan apa pun sumbernya: baris manual yang kemudian
    disumbang permohonan berganti sumber, tetapi alasannya tetap berarti.
--}}
@props(['detail', 'permohonan'])

<div {{ $attributes->class('min-w-0') }}>
    <x-ui.pill :warna="$detail->source->warna()" :ikon="$detail->source->icon()">
        {{ $detail->source->label() }}
    </x-ui.pill>

    @if ($permohonan->isNotEmpty())
        <ul class="text-theme-xs mt-1.5 space-y-0.5 text-gray-500 dark:text-gray-400">
            @foreach ($permohonan as $asal)
                <li>
                    <a href="{{ route('item-applications.show', $asal->application) }}"
                        class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ $asal->application->application_number }}</a>
                    · {{ $asal->application->user?->fullname ?? '-' }} · {{ number_format($asal->quantity, 0, ',', '.') }}
                </li>
            @endforeach
        </ul>
    @endif

    @if (filled($detail->note))
        <p class="text-theme-xs mt-1.5 flex max-w-xs items-start gap-1.5 text-gray-600 dark:text-gray-300">
            <x-ui.icon name="message-square" class="mt-0.5 size-3.5 text-teal-500 dark:text-teal-400" />
            <span class="italic">{{ $detail->note }}</span>
        </p>
    @endif
</div>
