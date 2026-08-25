<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurements', function (Blueprint $table) {
            $table->uuid('procurement_id')->primary();
            $table->date('procurement_date');
            $table->string('status');
            $table->string('bast_scan')->nullable();
            $table->string('additional_doc')->nullable();
            $table->foreignUuid('user_id')->constrained('users', 'user_id')->restrictOnDelete();
            $table->string('supplier');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurements');
    }
};