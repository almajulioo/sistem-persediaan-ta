<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_documents', function (Blueprint $table) {
            $table->foreignUuid('approved_by_user_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users', 'user_id')
                ->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('file_path');
            $table->string('verification_token_hash', 64)->nullable()->unique()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('request_documents', function (Blueprint $table) {
            $table->dropForeign(['approved_by_user_id']);
            $table->dropUnique(['verification_token_hash']);
            $table->dropColumn(['approved_by_user_id', 'approved_at', 'verification_token_hash']);
        });
    }
};