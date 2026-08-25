<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nota_dinas', function (Blueprint $table) {
            $table->uuid('nota_dinas_id')->primary();
            $table->foreignUuid('request_id')->constrained('requests', 'request_id')->cascadeOnDelete();
            $table->string('nd_number');
            $table->string('file_url');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nota_dinas');
    }
};