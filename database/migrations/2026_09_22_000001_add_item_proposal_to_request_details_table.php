<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_details', function (Blueprint $table) {
            $table->foreignUuid('item_id')->nullable()->change();
            $table->foreignUuid('item_proposal_id')
                ->nullable()
                ->after('item_id')
                ->constrained('item_proposals', 'item_proposal_id')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('request_details', function (Blueprint $table) {
            $table->dropForeign(['item_proposal_id']);
            $table->dropColumn('item_proposal_id');
            $table->foreignUuid('item_id')->nullable(false)->change();
        });
    }
};