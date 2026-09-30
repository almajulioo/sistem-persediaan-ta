<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pemilik akun mengganti emailnya sendiri (ADR 0038).
 *
 * Wajib menyertakan kata sandi saat ini karena email bukan sekadar data
 * kontak: `LoginRequest` menerima nama pengguna *atau* email, jadi mengganti
 * email berarti mengganti salah satu pengenal masuk. Sesi yang ditinggal
 * terbuka tidak boleh cukup untuk itu.
 *
 * Hanya `email` yang ada di aturan. Nama lengkap, nama pengguna, peran, dan
 * unit tetap wewenang Admin — isian selundupan untuk kolom itu terbuang
 * karena `validated()` hanya mengembalikan yang diatur di sini.
 */
class UpdateProfileEmailRequest extends FormRequest
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
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->user_id, 'user_id')],
            // Nama isian sengaja berbeda dari `current_password` pada formulir
            // kata sandi: keduanya satu halaman, dan nama yang sama membuat
            // pesan galat satu formulir ikut muncul di formulir lainnya.
            'email_password' => ['required', 'current_password'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => trim((string) $this->input('email'))]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'email',
            'email_password' => 'kata sandi saat ini',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.max' => 'Email maksimal :max karakter.',
            'email.unique' => 'Email sudah dipakai akun lain.',
            'email_password.current_password' => 'Kata sandi saat ini salah.',
        ];
    }
}
