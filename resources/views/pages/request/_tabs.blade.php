{{--
    Tab kategori di daftar permintaan dan permohonan. Tiap kategori punya
    ikon dan warnanya sendiri; ikon tetap berwarna saat tab tidak aktif supaya
    kategorinya terbaca sekilas.

    $tabs      : list { label, jumlah, ikon, warna, url, aktif }
    $ariaLabel : string
--}}
@use('App\Support\Warna')

<nav class="custom-scrollbar flex gap-1 overflow-x-auto" aria-label="{{ $ariaLabel }}">
    @foreach ($tabs as $tab)
        <a href="{{ $tab['url'] }}" @if ($tab['aktif']) aria-current="page" @endif
            @class([
                'text-theme-sm -mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-3 py-3.5 font-medium transition',
                Warna::tab($tab['warna']) => $tab['aktif'],
                'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300' => ! $tab['aktif'],
            ])>
            <x-ui.icon :name="$tab['ikon']" @class(['size-4', Warna::teks($tab['warna']) => ! $tab['aktif']]) />
            {{ $tab['label'] }}
            <span @class([
                'text-theme-xs rounded-full px-2 py-0.5',
                Warna::lembut($tab['warna']) => $tab['aktif'],
                'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400' => ! $tab['aktif'],
            ])>{{ $tab['jumlah'] }}</span>
        </a>
    @endforeach
</nav>
