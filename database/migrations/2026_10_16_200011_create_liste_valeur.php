<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Listes de valeurs gérées dans le référentiel (types d'article, motifs, techniciens…), proposées dans les formulaires. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liste_valeur', function (Blueprint $table) {
            $table->id();
            $table->string('liste', 32);
            $table->string('libelle');
            $table->boolean('actif')->default(true);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();
            $table->unique(['liste', 'libelle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liste_valeur');
    }
};
