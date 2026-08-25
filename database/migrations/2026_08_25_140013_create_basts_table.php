<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('basts', function (Blueprint $table) {
            $table->uuid('bast_id')->primary();
            $table->foreignUuid('request_id')->constrained('requests', 'request_id')->cascadeOnDelete();
            $table->string('bast_number');
            $table->string('file_url');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('basts');
    }
};