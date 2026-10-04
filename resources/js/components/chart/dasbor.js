/**
 * Grafik dasbor: mutasi stok masuk/keluar dan nilai pengadaan diterima.
 *
 * Data datang dari atribut `data-grafik` yang dirender DashboardController.
 * Pasangan warna sudah diuji keterbedaannya bagi buta warna dan kontrasnya
 * terhadap latar kartu, terang maupun gelap; warna keluar memakai tingkat
 * berbeda per mode, sehingga grafik digambar ulang saat tema berganti.
 */

const WARNA = {
    terang: { masuk: '#465fff', keluar: '#eb6834', teks: '#667085', garis: '#e4e7ec', tooltip: 'light' },
    gelap: { masuk: '#465fff', keluar: '#d95926', teks: '#98a2b3', garis: '#1d2939', tooltip: 'dark' },
};

const angka = new Intl.NumberFormat('id-ID');
const ringkas = new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 });
const rupiah = (nilai) => `Rp${angka.format(nilai)}`;

const modeGelap = () => document.documentElement.classList.contains('dark');

function opsi(jenis, data) {
    const warna = modeGelap() ? WARNA.gelap : WARNA.terang;
    const mutasi = jenis === 'mutasi';

    return {
        series: mutasi
            ? [
                  { name: 'Masuk', data: data.masuk },
                  { name: 'Keluar', data: data.keluar },
              ]
            : [{ name: 'Nilai diterima', data: data.nilai }],
        colors: mutasi ? [warna.masuk, warna.keluar] : [warna.masuk],
        chart: {
            type: 'bar',
            height: 260,
            fontFamily: 'Outfit, sans-serif',
            toolbar: { show: false },
            zoom: { enabled: false },
            animations: { enabled: false },
            background: 'transparent',
        },
        plotOptions: {
            bar: {
                // Lebar dalam piksel, bukan persen, supaya batang tetap ramping
                // di layar lebar. Nilai piksel berlaku per batang, juga saat
                // dua seri berdampingan.
                columnWidth: '24px',
                borderRadius: 4,
                borderRadiusApplication: 'end',
            },
        },
        // Celah 2 px antar batang yang bersebelahan.
        stroke: { show: true, width: 2, colors: ['transparent'] },
        dataLabels: { enabled: false },
        legend: { show: false },
        grid: {
            borderColor: warna.garis,
            strokeDashArray: 0,
            xaxis: { lines: { show: false } },
            yaxis: { lines: { show: true } },
        },
        xaxis: {
            categories: data.bulan,
            axisBorder: { show: false },
            axisTicks: { show: false },
            labels: { style: { colors: warna.teks, fontSize: '12px' } },
            tooltip: { enabled: false },
        },
        yaxis: {
            min: 0,
            forceNiceScale: true,
            labels: {
                style: { colors: [warna.teks], fontSize: '12px' },
                formatter: mutasi ? (v) => angka.format(Math.round(v)) : (v) => ringkas.format(v),
            },
        },
        tooltip: {
            theme: warna.tooltip,
            shared: mutasi,
            intersect: false,
            x: { formatter: (_, { dataPointIndex }) => data.bulanPanjang[dataPointIndex] },
            y: { formatter: mutasi ? (v) => `${angka.format(v)} transaksi` : (v) => rupiah(v) },
        },
        states: { active: { filter: { type: 'none' } } },
    };
}

export const initGrafikDasbor = () => {
    const grafik = [...document.querySelectorAll('[data-grafik-dasbor]')].map((elemen) => {
        const jenis = elemen.dataset.grafikDasbor;
        const data = JSON.parse(elemen.dataset.grafik);
        const chart = new window.ApexCharts(elemen, opsi(jenis, data));
        chart.render();

        return { chart, jenis, data };
    });

    // Tema diganti lewat kelas `dark` pada <html> (lihat layouts/app).
    new MutationObserver(() => {
        grafik.forEach(({ chart, jenis, data }) => chart.updateOptions(opsi(jenis, data), false, false));
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
};
