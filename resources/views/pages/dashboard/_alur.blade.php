{{--
    Kartu alur bertahap di dasbor, dipakai alur permintaan dan alur pengadaan.

    $judul     : string — judul kartu
    $tahapan   : array  — dari DashboardController, tiap tahap berisi
                 { label, jumlah, url, ikon?, warna?, ditolak?: { jumlah, url } }
    $modulAlur : ?ModulPengajuan — bila ada, kartu bergaris dan berlabel
                 warna identitas modulnya

    Tahap yang punya `ikon` dan `warna` tampil sebagai lingkaran berikon
    berwarna statusnya, sama dengan badge di daftar; tanpa itu bernomor urut.
--}}
@php
    // Ditulis utuh supaya terbaca pemindai Tailwind.
    $kolomXl = count($tahapan) === 5 ? 'xl:grid-cols-5' : 'xl:grid-cols-6';
    $modulAlur ??= null;
@endphp

<section @class([$kartu, 'mt-6', 'border-t-[3px] '.$modulAlur?->aksenClass() => $modulAlur !== null])>
    @if ($modulAlur)
        <x-modul.label :modul="$modulAlur" class="mb-1.5" />
    @endif
    <h3 class="{{ $judulKartu }}">{{ $judul }}</h3>

    <ol class="mt-5 grid grid-cols-2 gap-x-3 gap-y-5 sm:grid-cols-3 {{ $kolomXl }} xl:gap-x-0">
        @foreach ($tahapan as $tahap)
            @php $berikon = isset($tahap['ikon'], $tahap['warna']); @endphp
            <li class="flex flex-col">
                <div class="flex items-center" aria-hidden="true">
                    <span @class([
                        'flex shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                        'size-9' => $berikon,
                        'size-7' => ! $berikon,
                        'text-white '.\App\Support\Warna::isi($tahap['warna'] ?? 'brand') => $tahap['jumlah'] > 0,
                        'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' => $tahap['jumlah'] === 0,
                    ])>
                        @if ($berikon)
                            <x-ui.icon :name="$tahap['ikon']" class="size-[18px]" />
                        @else
                            {{ $loop->iteration }}
                        @endif
                    </span>
                    @unless ($loop->last)
                        <span class="mx-3 hidden h-px flex-1 bg-gray-200 xl:block dark:bg-gray-800"></span>
                    @endunless
                </div>

                <a href="{{ $tahap['url'] }}" class="group mt-3 block pr-3">
                    <span class="text-theme-sm block text-gray-500 group-hover:text-gray-700 dark:text-gray-400 dark:group-hover:text-gray-300">
                        @if ($berikon)
                            <span class="text-gray-400 dark:text-gray-500">{{ $loop->iteration }}.</span>
                        @endif
                        {{ $tahap['label'] }}
                    </span>
                    <span @class([
                        'mt-1 block text-2xl font-semibold',
                        'text-gray-800 dark:text-white/90' => $tahap['jumlah'] > 0,
                        'text-gray-300 dark:text-gray-600' => $tahap['jumlah'] === 0,
                    ])>{{ $tahap['jumlah'] }}</span>
                </a>

                @isset($tahap['ditolak'])
                    <a href="{{ $tahap['ditolak']['url'] }}"
                        class="text-theme-xs text-error-600 dark:text-error-500 mt-1 inline-flex items-center gap-1.5 font-medium hover:underline">
                        <x-ui.icon name="x-circle" class="size-3.5" />
                        {{ $tahap['ditolak']['jumlah'] }} ditolak
                    </a>
                @endisset
            </li>
        @endforeach
    </ol>
</section>
