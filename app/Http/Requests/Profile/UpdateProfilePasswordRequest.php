<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Pemilik akun mengganti kata sandinya sendiri (ADR 0038).
 *
 * Kebijakannya sama persis dengan yang dipakai Admin saat membuat akun —
 * `Password::defaults()` di AppServiceProvider — supaya tidak ada jalur yang
 * lebih longgar untuk sampai ke kata sandi yang sama.
 */
class UpdateProfilePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'current_password' => 'kata sandi saat ini',
            'password' => 'kata sandi baru',
        ];
    }

    /**
     * Pesan kebijakan kata sandi ditulis di sini karena proyek belum memiliki
     * berkas terjemahan `lang/id`; tanpanya pesan `Password::defaults()` keluar
     * dalam bahasa Inggris.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'current_password.current_password' => 'Kata sandi saat ini salah.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
            'password.different' => 'Kata sandi baru harus berbeda dari kata sandi saat ini.',
            'password.min' => 'Kata sandi baru minimal :min karakter.',
            'password.letters' => 'Kata sandi baru harus memuat huruf.',
            'password.mixed' => 'Kata sandi baru harus memuat huruf besar dan huruf kecil.',
            'password.numbers' => 'Kata sandi baru harus memuat angka.',
            'password.symbols' => 'Kata sandi baru harus memuat simbol.',
            'password.uncompromised' => 'Kata sandi ini pernah muncul dalam kebocoran data publik. Pilih kata sandi lain.',
        ];
    }
}
