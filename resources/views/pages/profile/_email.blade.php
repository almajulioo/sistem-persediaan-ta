{{-- Email dapat dipakai untuk masuk, karena itu penggantiannya meminta kata sandi. --}}
<div class="rounded-2xl border border-gray-200 bg-white p-5 md:p-6 dark:border-gray-800 dark:bg-white/[0.03]">
    <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Email</h3>

    <form method="POST" action="{{ route('profile.email.update') }}" class="mt-5 space-y-5">
        @csrf
        @method('PATCH')

        <x-form.text-field name="email" label="Email" type="email" :value="$user->email" required
            autocomplete="email" />

        <x-form.password-field name="email_password" label="Kata Sandi Saat Ini" required
            autocomplete="current-password" placeholder="Masukkan kata sandi Anda" />

        <div class="flex justify-end">
            <button type="submit"
                class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 rounded-lg px-5 py-3 text-sm font-medium text-white transition">
                Simpan Email
            </button>
        </div>
    </form>
</div>
