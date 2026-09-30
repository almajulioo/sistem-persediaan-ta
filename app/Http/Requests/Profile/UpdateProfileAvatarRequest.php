<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Unggahan foto profil milik sendiri (ADR 0038).
 *
 * Di jalur normal foto sudah dipotong persegi 512×512 dan dijadikan JPEG oleh
 * peramban, sehingga ukurannya jauh di bawah batas. Aturan di sini adalah
 * jaring pengaman untuk jalur cadangan — JavaScript gagal atau dimatikan —
 * saat berkas asli yang terkirim.
 *
 * HEIC sengaja tidak diterima, berbeda dari foto belanja: foto profil tampil
 * di setiap halaman, dan hanya Safari yang mampu menampilkan HEIC.
 */
class UpdateProfileAvatarRequest extends FormRequest
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
            // 2 MB: sama dengan `upload_max_filesize` bawaan PHP, sehingga
            // pesan di bawah yang muncul, bukan permintaan kosong.
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=96,min_height=96'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.required' => 'Pilih foto yang akan diunggah.',
            'avatar.image' => 'Berkas harus berupa gambar.',
            'avatar.mimes' => 'Foto harus berformat JPG, PNG, atau WEBP.',
            'avatar.max' => 'Ukuran foto maksimal 2 MB.',
            'avatar.dimensions' => 'Foto terlalu kecil. Minimal 96×96 piksel.',
            'avatar.uploaded' => 'Foto gagal diunggah. Ukurannya kemungkinan melebihi batas unggah server.',
        ];
    }
}
