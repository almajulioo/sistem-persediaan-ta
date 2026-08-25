<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('requests', function (Blueprint $table) {
            $table->uuid('request_id')->primary();
            $table->foreignUuid('user_id')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();
            $table->date('date');
            $table->string('type');
            $table->string('status');
            $table->string('bast_number')->nullable();
            $table->string('nd_number')->nullable();
            $table->unsignedInteger('request_amount');
            $table->unsignedInteger('accept_amount')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('requests');
    }
};
