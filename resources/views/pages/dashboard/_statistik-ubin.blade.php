{{--
    Ubin angka kecil di kartu Permintaan/Permohonan dasbor, dengan chip ikon
    berwarna kategori statusnya.

    $statistik : { label, jumlah, url, ikon, warna }
--}}
<a href="{{ $statistik['url'] }}"
    class="block h-full rounded-xl bg-gray-50 px-3 py-3 transition hover:bg-gray-100 dark:bg-white/[0.03] dark:hover:bg-white/[0.06]">
    <span class="flex items-start gap-1.5">
        <span class="flex size-6 shrink-0 items-center justify-center rounded-md {{ \App\Support\Warna::lembut($statistik['warna']) }}">
            <x-ui.icon :name="$statistik['ikon']" class="size-3.5" />
        </span>
        {{-- Membungkus, bukan terpotong: label tahap permohonan cukup panjang
             dan "Disetujui Kepala Unit" tidak boleh terbaca sama dengan "Disetujui". --}}
        <span class="text-theme-xs min-w-0 leading-tight text-gray-500 dark:text-gray-400">{{ $statistik['label'] }}</span>
    </span>
    <span @class([
        'mt-1.5 block text-2xl font-semibold',
        'text-gray-800 dark:text-white/90' => $statistik['jumlah'] > 0,
        'text-gray-300 dark:text-gray-600' => $statistik['jumlah'] === 0,
    ])>{{ $statistik['jumlah'] }}</span>
</a>
