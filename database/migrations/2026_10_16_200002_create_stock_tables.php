<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock : réceptions, unités d'article, kits assemblés, sorties et mouvements.
 * Règles portées en base : RG04, RG06, RG07, RG08, RG09, RG24, RG25, RG27 (dossier v2, sections 2.2 et 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reception', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('num_bl', 64);
            $table->string('fournisseur');
            $table->foreignId('utilisateur_id')->constrained('users');
            $table->timestamps();
        });

        Schema::create('unite_article', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 32)->unique(); // RG08
            $table->string('nature', 8); // serie | lot
            $table->string('numero_serie', 64)->nullable();
            $table->foreignId('fabricant_id')->nullable()->constrained('fabricant'); // recopié depuis l'article (RG07)
            $table->unsignedInteger('quantite_initiale');
            $table->unsignedInteger('quantite_restante');
            $table->string('statut', 16)->default('en_stock');
            $table->string('emplacement', 64)->nullable();
            $table->foreignId('article_id')->constrained('article');
            $table->foreignId('reception_id')->nullable()->constrained('reception');
            $table->timestamps();
            $table->unique(['fabricant_id', 'numero_serie']); // RG07
        });
        DB::statement(<<<'SQL'
            ALTER TABLE unite_article
                ADD CONSTRAINT unite_article_nature CHECK (
                    (nature = 'serie' AND numero_serie IS NOT NULL AND quantite_initiale = 1)
                    OR (nature = 'lot' AND numero_serie IS NULL AND quantite_initiale >= 1)
                ),
                ADD CONSTRAINT unite_article_quantite CHECK (quantite_restante BETWEEN 0 AND quantite_initiale),
                ADD CONSTRAINT unite_article_statut CHECK (
                    statut IN ('en_stock', 'assemble', 'epuise', 'expedie', 'rebute', 'retourne', 'perdu')
                )
        SQL);

        // RG04 + RG07 : la nature suit l'article et le fabricant est recopié pour l'unicité du n° de série.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION trg_unite_article_fabricant() RETURNS trigger AS $$
            DECLARE
                v_serialise boolean;
                v_fabricant bigint;
                v_reference text;
            BEGIN
                SELECT est_serialise, fabricant_id, reference INTO v_serialise, v_fabricant, v_reference
                FROM article WHERE id = NEW.article_id;
                IF v_serialise AND NEW.nature <> 'serie' OR NOT v_serialise AND NEW.nature <> 'lot' THEN
                    RAISE EXCEPTION 'RG04 : la nature % ne correspond pas à l''article %', NEW.nature, v_reference
                        USING ERRCODE = 'check_violation';
                END IF;
                IF v_serialise AND v_fabricant IS NULL THEN
                    RAISE EXCEPTION 'RG07 : l''article sérialisé % n''a pas de fabricant', v_reference
                        USING ERRCODE = 'check_violation';
                END IF;
                NEW.fabricant_id := v_fabricant;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER unite_article_fabricant_rg07
                BEFORE INSERT OR UPDATE OF article_id, nature ON unite_article
                FOR EACH ROW EXECUTE FUNCTION trg_unite_article_fabricant();
        SQL);

        Schema::create('kit_assemble', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 32)->unique(); // RG08
            $table->string('numero_serie', 64); // hérité du porteur (RG06)
            $table->string('statut', 16)->default('assemble');
            $table->timestamp('assemble_le');
            $table->foreignId('version_kit_id')->constrained('version_kit');
            $table->foreignId('unite_porteur_id')->constrained('unite_article');
            $table->foreignId('utilisateur_id')->constrained('users');
            $table->timestamp('desassemble_le')->nullable();
            $table->text('motif_desassemblage')->nullable();
            $table->timestamps();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE kit_assemble
                ADD CONSTRAINT kit_assemble_statut CHECK (statut IN ('assemble', 'desassemble', 'expedie')),
                ADD CONSTRAINT kit_assemble_desassemblage CHECK (
                    statut <> 'desassemble' OR (desassemble_le IS NOT NULL AND motif_desassemblage IS NOT NULL)
                )
        SQL);
        // RG09 + RG25 : un porteur n'appartient qu'à un kit non désassemblé.
        DB::statement("CREATE UNIQUE INDEX kit_assemble_porteur_actif ON kit_assemble (unite_porteur_id) WHERE statut <> 'desassemble'");

        // RG06 : le kit reprend le n° de série de son porteur.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION trg_kit_assemble_serie() RETURNS trigger AS $$
            BEGIN
                SELECT numero_serie INTO NEW.numero_serie FROM unite_article WHERE id = NEW.unite_porteur_id;
                IF NEW.numero_serie IS NULL THEN
                    RAISE EXCEPTION 'RG06 : le porteur % n''a pas de n° de série', NEW.unite_porteur_id
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER kit_assemble_serie_rg06
                BEFORE INSERT OR UPDATE OF unite_porteur_id ON kit_assemble
                FOR EACH ROW EXECUTE FUNCTION trg_kit_assemble_serie();
        SQL);

        Schema::create('contenu_kit', function (Blueprint $table) {
            $table->foreignId('kit_id')->constrained('kit_assemble');
            $table->foreignId('unite_id')->constrained('unite_article');
            $table->unsignedInteger('quantite');
            $table->primary(['kit_id', 'unite_id']);
        });
        DB::statement('ALTER TABLE contenu_kit ADD CONSTRAINT contenu_kit_quantite_min CHECK (quantite >= 1)');

        Schema::create('sortie', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('type', 24);
            $table->string('destinataire')->nullable();
            $table->string('document', 64)->nullable();
            $table->foreignId('utilisateur_id')->constrained('users');
            $table->timestamps();
        });
        DB::statement("ALTER TABLE sortie ADD CONSTRAINT sortie_type CHECK (type IN ('expedition', 'rebut', 'retour_fournisseur'))");

        // RG24 : toute variation de stock est un mouvement daté et signé.
        Schema::create('mouvement_stock', function (Blueprint $table) {
            $table->id();
            $table->timestamp('horodatage')->useCurrent();
            $table->string('type', 16);
            $table->integer('quantite');
            $table->foreignId('unite_id')->nullable()->constrained('unite_article');
            $table->foreignId('kit_id')->nullable()->constrained('kit_assemble');
            $table->foreignId('reception_id')->nullable()->constrained('reception');
            $table->foreignId('sortie_id')->nullable()->constrained('sortie');
            $table->text('motif')->nullable();
            $table->foreignId('utilisateur_id')->constrained('users');
            $table->index(['unite_id', 'horodatage']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE mouvement_stock
                ADD CONSTRAINT mouvement_stock_type CHECK (
                    type IN ('entree', 'consommation', 'restitution', 'sortie', 'ajustement')
                ),
                ADD CONSTRAINT mouvement_stock_quantite CHECK (quantite <> 0),
                ADD CONSTRAINT mouvement_stock_cible CHECK (num_nonnulls(unite_id, kit_id) = 1),
                ADD CONSTRAINT mouvement_stock_motif CHECK (type <> 'ajustement' OR motif IS NOT NULL),
                ADD CONSTRAINT mouvement_stock_sortie CHECK (type <> 'sortie' OR sortie_id IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvement_stock');
        Schema::dropIfExists('sortie');
        Schema::dropIfExists('contenu_kit');
        Schema::dropIfExists('kit_assemble');
        Schema::dropIfExists('unite_article');
        Schema::dropIfExists('reception');
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS trg_kit_assemble_serie();
            DROP FUNCTION IF EXISTS trg_unite_article_fabricant();
        SQL);
    }
};
