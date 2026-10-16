<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Simulations enregistrées pour être relues ; elles ne touchent jamais le stock (RG13, dossier v2 section 5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulation', function (Blueprint $table) {
            $table->id();
            $table->timestamp('horodatage')->useCurrent();
            $table->string('mode', 16)->default('priorite');
            $table->string('source_stock', 8);
            $table->string('statut', 16)->default('en_cours');
            $table->string('optimalite', 16)->nullable();
            $table->unsignedInteger('duree_ms')->nullable();
            $table->foreignId('utilisateur_id')->constrained('users');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE simulation
                ADD CONSTRAINT simulation_mode CHECK (mode IN ('priorite', 'equilibre', 'volume')),
                ADD CONSTRAINT simulation_source CHECK (source_stock IN ('saisie', 'import', 'reel')),
                ADD CONSTRAINT simulation_statut CHECK (statut IN ('en_cours', 'termine', 'echec')),
                ADD CONSTRAINT simulation_optimalite CHECK (optimalite IN ('optimal', 'realisable', 'non_resolu'))
        SQL);

        Schema::create('sim_demande', function (Blueprint $table) {
            $table->foreignId('simulation_id')->constrained('simulation')->cascadeOnDelete();
            $table->foreignId('version_kit_id')->constrained('version_kit');
            $table->unsignedInteger('qte_voulue');
            $table->unsignedSmallInteger('rang_priorite')->default(1);
            $table->unsignedInteger('qte_realisable')->nullable();
            $table->primary(['simulation_id', 'version_kit_id']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE sim_demande
                ADD CONSTRAINT sim_demande_rang CHECK (rang_priorite >= 1),
                ADD CONSTRAINT sim_demande_realisable CHECK (qte_realisable IS NULL OR qte_realisable <= qte_voulue)
        SQL);

        Schema::create('sim_stock', function (Blueprint $table) {
            $table->foreignId('simulation_id')->constrained('simulation')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('article');
            $table->unsignedInteger('qte_disponible');
            $table->unsignedInteger('qte_consommee')->nullable();
            $table->unsignedInteger('qte_manquante')->nullable();
            $table->unsignedInteger('qte_reliquat')->nullable();
            $table->primary(['simulation_id', 'article_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sim_stock');
        Schema::dropIfExists('sim_demande');
        Schema::dropIfExists('simulation');
    }
};
