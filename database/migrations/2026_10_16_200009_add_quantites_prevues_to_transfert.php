<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bon de transfert : quantités prévues par article ou type de kit, avant le scan (RG34). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfert', function (Blueprint $table) {
            $table->jsonb('quantites_prevues')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transfert', fn (Blueprint $table) => $table->dropColumn('quantites_prevues'));
    }
};
