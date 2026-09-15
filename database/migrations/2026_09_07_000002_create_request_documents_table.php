<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_documents', function (Blueprint $table) {
            $table->uuid('request_document_id')->primary();
            $table->foreignUuid('request_id')
                ->constrained('requests', 'request_id')
                ->cascadeOnDelete();
            $table->foreignUuid('user_id')
                ->constrained('users', 'user_id')
                ->restrictOnDelete();
            $table->enum('document_type', [
               'nota_dinas',
               'bast',
            ]);
            $table->string('document_number')->nullable();
            $table->string('file_path');
            $table->timestamps();
            $table->unique(['request_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_documents');
    }
};
