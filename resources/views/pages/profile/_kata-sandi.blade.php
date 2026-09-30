{{--
    Ganti kata sandi sendiri. Daftar syaratnya dibangun dari
    `Password::defaults()->appliedRules()`, jadi hanya syarat yang benar-benar
    berlaku yang tampil — dan baru muncul begitu kata sandi baru mulai
    diketik, supaya kartunya tetap bersih. `x-form.password-field` tidak
    meneruskan atribut ke <input>-nya, maka ketikan ditangkap lewat event
    `input` yang menggelembung.
--}}
@php
    $syarat = array_filter([
        'cukupPanjang' => 'Minimal '.$kebijakanSandi['min'].' karakter',
        'hurufBesarKecil' => $kebijakanSandi['mixedCase'] ? 'Huruf besar dan huruf kecil' : null,
        'adaHuruf' => $kebijakanSandi['letters'] && ! $kebijakanSandi['mixedCase'] ? 'Memuat huruf' : null,
        'adaAngka' => $kebijakanSandi['numbers'] ? 'Memuat angka' : null,
        'adaSimbol' => $kebijakanSandi['symbols'] ? 'Memuat simbol, misalnya # atau !' : null,
    ]);
@endphp

<div x-data="syaratSandi({{ (int) $kebijakanSandi['min'] }})"
    @input="if ($event.target.name === 'password') sandi = $event.target.value"
    class="rounded-2xl border border-gray-200 bg-white p-5 md:p-6 dark:border-gray-800 dark:bg-white/[0.03]">
    <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Ganti Kata Sandi</h3>

    <form method="POST" action="{{ route('profile.password.update') }}" class="mt-5 space-y-5">
        @csrf
        @method('PUT')

        <x-form.password-field name="current_password" label="Kata Sandi Saat Ini" required
            autocomplete="current-password" placeholder="Masukkan kata sandi saat ini" />

        <div>
            <x-form.password-field name="password" label="Kata Sandi Baru" required
                autocomplete="new-password" placeholder="Masukkan kata sandi baru" />

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

        <x-form.password-field name="password_confirmation" label="Konfirmasi Kata Sandi Baru" required
            autocomplete="new-password" placeholder="Ulangi kata sandi baru" />

        <div class="flex justify-end">
            <button type="submit"
                class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 rounded-lg px-5 py-3 text-sm font-medium text-white transition">
                Ganti Kata Sandi
            </button>
        </div>
    </form>
</div>
