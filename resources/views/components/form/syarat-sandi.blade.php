{{--
    Bungkus kolom kata sandi baru beserta daftar syaratnya, dipakai profil
    dan formulir pengguna milik Admin.

    Syaratnya dibaca dari `Password::defaults()->appliedRules()` — aturan yang
    sama dengan validasinya — jadi hanya syarat yang benar-benar berlaku yang
    tampil, dan daftarnya tidak tertinggal bila kebijakannya diubah. Daftar
    baru muncul begitu kolom bernama `password` mulai diketik, supaya
    formulirnya tetap bersih. `x-form.password-field` tidak meneruskan atribut
    ke <input>-nya, maka ketikan ditangkap lewat event `input` yang
    menggelembung sampai ke bungkus ini.
--}}
@php
    $kebijakan = \Illuminate\Validation\Rules\Password::defaults()->appliedRules();

    $syarat = array_filter([
        'cukupPanjang' => 'Minimal '.$kebijakan['min'].' karakter',
        'hurufBesarKecil' => $kebijakan['mixedCase'] ? 'Huruf besar dan huruf kecil' : null,
        'adaHuruf' => $kebijakan['letters'] && ! $kebijakan['mixedCase'] ? 'Memuat huruf' : null,
        'adaAngka' => $kebijakan['numbers'] ? 'Memuat angka' : null,
        'adaSimbol' => $kebijakan['symbols'] ? 'Memuat simbol, misalnya # atau !' : null,
    ]);
@endphp

<div x-data="syaratSandi({{ (int) $kebijakan['min'] }})"
    @input="if ($event.target.name === 'password') sandi = $event.target.value"
    {{ $attributes }}>
    {{ $slot }}

    <ul x-show="sandi" x-cloak class="text-theme-xs mt-3 grid grid-cols-1 gap-1.5 sm:grid-cols-2" aria-label="Syarat kata sandi">
        @foreach ($syarat as $uji => $label)
            <li class="flex items-center gap-2 transition"
                :class="{{ $uji }} ? 'text-success-600 dark:text-success-500' : 'text-gray-500 dark:text-gray-400'">
                <svg class="size-4 shrink-0 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path x-show="{{ $uji }}" fill-rule="evenodd" clip-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.7-9.3a1 1 0 0 0-1.4-1.4L9 10.6 7.7 9.3a1 1 0 0 0-1.4 1.4l2 2a1 1 0 0 0 1.4 0l4-4Z" />
                    <path x-show="! {{ $uji }}" fill-rule="evenodd" clip-rule="evenodd" d="M10 16.5a6.5 6.5 0 1 0 0-13 6.5 6.5 0 0 0 0 13Zm0 1.5a8 8 0 1 0 0-16 8 8 0 0 0 0 16Z" />
                </svg>
                {{ $label }}
            </li>
        @endforeach
    </ul>
</div>

@once
    @push('scripts')
        <script>
            /**
             * Daftar syarat kata sandi yang tercentang saat mengetik. Hanya
             * petunjuk — penentunya tetap Password::defaults() di server. Pola
             * regex-nya sama dengan yang dipakai aturan Password milik Laravel.
             */
            window.syaratSandi = function (min) {
                return {
                    sandi: '',

                    get cukupPanjang() {
                        return this.sandi.length >= min;
                    },

                    get hurufBesarKecil() {
                        return /\p{Ll}/u.test(this.sandi) && /\p{Lu}/u.test(this.sandi);
                    },

                    get adaHuruf() {
                        return /\p{L}/u.test(this.sandi);
                    },

                    get adaAngka() {
                        return /\p{N}/u.test(this.sandi);
                    },

                    get adaSimbol() {
                        return /[\p{Z}\p{S}\p{P}]/u.test(this.sandi);
                    },
                };
            };
        </script>
    @endpush
@endonce
