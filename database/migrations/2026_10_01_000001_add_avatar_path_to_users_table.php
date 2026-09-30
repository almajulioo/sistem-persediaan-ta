<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Path foto profil pada disk privat, relatif terhadap disk tersebut.
     *
     * Nullable dan murni aditif: akun tanpa foto tampil dengan avatar inisial.
     * `users` adalah tabel bersama dengan sub sistem Permintaan, jadi kolom ini
     * menunggu persetujuan Alma. Lihat ADR 0038.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('fullname');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
