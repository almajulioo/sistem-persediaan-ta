{{--
    Pembungkus halaman berformulir yang tidak boleh terkirim dua kali (ADR 0045).

    Tombol Kembali memulihkan halaman dari back-forward cache peramban lengkap
    dengan isian dan keadaan Alpine-nya, jadi formulir yang baru terkirim siap
    dikirim lagi. Bila itu terjadi setelah salah satu formulir di dalamnya
    terkirim, halaman dimuat ulang supaya yang tampil keadaan terbaru.
    `terkirim` juga dipakai menonaktifkan tombol kirim, mencegah klik ganda.

    Ini hanya kenyamanan: jaminannya tetap di server (token kiriman pada
    usulan manual, `draft_id` pada draf). Kiriman yang dibatalkan, mis. lewat
    `confirm()` di `onsubmit`, tidak dihitung.
--}}
<div x-data="{ terkirim: false }"
    @submit="if (! $event.defaultPrevented) terkirim = true"
    @pageshow.window="if ($event.persisted && terkirim) location.reload()"
    {{ $attributes }}>
    {{ $slot }}
</div>
