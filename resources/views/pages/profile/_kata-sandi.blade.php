{{--
    Ganti kata sandi sendiri. Daftar syaratnya datang dari
    `x-form.syarat-sandi` dan baru muncul begitu kata sandi baru mulai
    diketik, supaya kartunya tetap bersih.
--}}
<div class="rounded-2xl border border-gray-200 bg-white p-5 md:p-6 dark:border-gray-800 dark:bg-white/[0.03]">
    <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Ganti Kata Sandi</h3>

    <form method="POST" action="{{ route('profile.password.update') }}" class="mt-5 space-y-5">
        @csrf
        @method('PUT')

        <x-form.password-field name="current_password" label="Kata Sandi Saat Ini" required
            autocomplete="current-password" placeholder="Masukkan kata sandi saat ini" />

        <x-form.syarat-sandi>
            <x-form.password-field name="password" label="Kata Sandi Baru" required
                autocomplete="new-password" placeholder="Masukkan kata sandi baru" />
        </x-form.syarat-sandi>

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
