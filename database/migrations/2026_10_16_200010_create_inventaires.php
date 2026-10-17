<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campagnes d'inventaire (RG35 à RG38) et coûts unitaires pour valoriser les écarts.
 *  - Une campagne a une date, des sites (entrepôt, centres) et un périmètre (catégories, articles, types de kit).
 *  - Au lancement, le stock théorique est figé ligne par ligne (site × article ou type de kit).
 *  - Comptages : 1er comptage, 2e comptage sur demande du contrôleur de gestion pour les lignes en écart,
 *    puis décompte final arrêté par le contrôleur. Les écarts sont valorisés au coût unitaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article', fn (Blueprint $t) => $t->decimal('cout_unitaire', 14, 2)->nullable());
        Schema::table('type_kit', fn (Blueprint $t) => $t->decimal('cout_unitaire', 14, 2)->nullable());
        DB::statement('ALTER TABLE article ADD CONSTRAINT article_cout_positif CHECK (cout_unitaire IS NULL OR cout_unitaire >= 0)');
        DB::statement('ALTER TABLE type_kit ADD CONSTRAINT type_kit_cout_positif CHECK (cout_unitaire IS NULL OR cout_unitaire >= 0)');

        Schema::create('inventaire', function (Blueprint $table) {
            $table->id();
            $table->string('libelle');
            $table->date('date_prevue');
            $table->string('statut', 16)->default('brouillon');
            $table->unsignedSmallInteger('tour')->default(0); // comptage en cours : 1, 2 ; 3 = décompte final
            $table->boolean('tous_articles')->default(false);
            $table->boolean('tous_kits')->default(false);
            $table->timestamp('lance_le')->nullable();
            $table->decimal('valeur_ecart', 16, 2)->nullable();
            $table->boolean('stock_ajuste')->default(false);
            $table->text('commentaire')->nullable();
            $table->foreignId('cree_par')->constrained('users');
            $table->foreignId('cloture_par')->nullable()->constrained('users');
            $table->timestamp('cloture_le')->nullable();
            $table->timestamps();
        });
        // brouillon → comptage (tour 1) → analyse → [comptage (tour 2) →] decompte → cloture
        DB::statement("ALTER TABLE inventaire ADD CONSTRAINT inventaire_statut CHECK (statut IN ('brouillon', 'comptage', 'analyse', 'decompte', 'cloture', 'annule'))");

        Schema::create('inventaire_site', function (Blueprint $table) {
            $table->foreignId('inventaire_id')->constrained('inventaire')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('site');
            $table->primary(['inventaire_id', 'site_id']);
        });

        // Périmètre : catégories, articles ou types de kit (en plus de « tous les articles / tous les kits »).
        Schema::create('inventaire_cible', function (Blueprint $table) {
            $table->foreignId('inventaire_id')->constrained('inventaire')->cascadeOnDelete();
            $table->string('type', 12); // categorie | article | type_kit
            $table->unsignedBigInteger('cible_id');
            $table->primary(['inventaire_id', 'type', 'cible_id']);
        });
        DB::statement("ALTER TABLE inventaire_cible ADD CONSTRAINT inventaire_cible_type CHECK (type IN ('categorie', 'article', 'type_kit'))");

        // Une ligne par site et par article ou type de kit : théorique figé au lancement, comptages, final, écart valorisé.
        Schema::create('inventaire_ligne', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventaire_id')->constrained('inventaire')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('site');
            $table->foreignId('article_id')->nullable()->constrained('article');
            $table->foreignId('type_kit_id')->nullable()->constrained('type_kit');
            $table->integer('theorique');
            $table->decimal('cout_unitaire', 14, 2)->nullable();
            $table->integer('comptage1')->nullable();
            $table->integer('comptage2')->nullable();
            $table->boolean('recompter')->default(false);
            $table->integer('final')->nullable();
            $table->text('justification')->nullable();
            $table->integer('ecart')->nullable();
            $table->decimal('valeur_ecart', 16, 2)->nullable();
            $table->unique(['inventaire_id', 'site_id', 'article_id', 'type_kit_id']);
        });
        DB::statement('ALTER TABLE inventaire_ligne ADD CONSTRAINT inventaire_ligne_cible CHECK (num_nonnulls(article_id, type_kit_id) = 1)');

        // Détail des comptages : chaque scan ou saisie, par tour.
        Schema::create('inventaire_comptage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventaire_id')->constrained('inventaire')->cascadeOnDelete();
            $table->foreignId('ligne_id')->nullable()->constrained('inventaire_ligne')->cascadeOnDelete();
            $table->unsignedSmallInteger('tour');
            $table->foreignId('site_id')->constrained('site');
            $table->foreignId('emplacement_id')->nullable()->constrained('emplacement');
            $table->foreignId('unite_id')->nullable()->constrained('unite_article');
            $table->foreignId('kit_id')->nullable()->constrained('kit_assemble');
            $table->string('code_lu', 64)->nullable(); // n° de série inconnu du système, à régulariser
            $table->integer('quantite');
            $table->foreignId('utilisateur_id')->constrained('users');
            $table->timestamp('horodatage')->useCurrent();
            $table->index(['inventaire_id', 'tour']);
        });
        DB::statement('ALTER TABLE inventaire_comptage ADD CONSTRAINT inventaire_comptage_quantite CHECK (quantite > 0)');
        // Un kit n'est compté qu'une fois par tour ; l'unicité des articles sérialisés est contrôlée par l'application
        // (un lot peut, lui, être compté en plusieurs fois).
        DB::statement('CREATE UNIQUE INDEX inventaire_comptage_kit_unique ON inventaire_comptage (inventaire_id, tour, kit_id) WHERE kit_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventaire_comptage');
        Schema::dropIfExists('inventaire_ligne');
        Schema::dropIfExists('inventaire_cible');
        Schema::dropIfExists('inventaire_site');
        Schema::dropIfExists('inventaire');
        DB::statement('ALTER TABLE type_kit DROP CONSTRAINT IF EXISTS type_kit_cout_positif');
        DB::statement('ALTER TABLE article DROP CONSTRAINT IF EXISTS article_cout_positif');
        Schema::table('type_kit', fn (Blueprint $t) => $t->dropColumn('cout_unitaire'));
        Schema::table('article', fn (Blueprint $t) => $t->dropColumn('cout_unitaire'));
    }
};
