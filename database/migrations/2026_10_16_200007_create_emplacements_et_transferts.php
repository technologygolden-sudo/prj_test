<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sites, emplacements virtuels, propriété GREENO/TEVIA, état (bon/défectueux), transferts entre sites,
 * déplacements internes motivés et déclarations SAV/Lab.
 *
 * Règles portées en base :
 *  - RG28 : un élément défectueux n'entre jamais dans un emplacement de vente ;
 *  - RG29 : dans un espace GREENO ou TEVIA, seul le stock de ce propriétaire peut être rangé ;
 *  - RG30 : tout changement d'emplacement est tracé (table mouvement_emplacement, motif obligatoire pour un déplacement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('nom');
            $table->string('type', 16); // entrepot | centre_service
            $table->string('ville')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE site ADD CONSTRAINT site_type CHECK (type IN ('entrepot', 'centre_service'))");

        Schema::create('emplacement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('site');
            $table->string('espace', 8)->nullable(); // GREENO | TEVIA | NULL (partagé)
            $table->string('type', 16);
            $table->string('nom');
            $table->boolean('vente')->default(false);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE emplacement
                ADD CONSTRAINT emplacement_espace CHECK (espace IN ('GREENO', 'TEVIA')),
                ADD CONSTRAINT emplacement_type CHECK (type IN ('reception', 'entrepot', 'lab', 'shop', 'sav', 'vente', 'retour', 'defectueux')),
                ADD CONSTRAINT emplacement_unique UNIQUE NULLS NOT DISTINCT (site_id, espace, type)
        SQL);

        Schema::table('reception', function (Blueprint $t) {
            $t->string('espace', 8)->nullable(); // GREENO | TEVIA : propriétaire des articles reçus
        });
        DB::statement("ALTER TABLE reception ADD CONSTRAINT reception_espace CHECK (espace IN ('GREENO', 'TEVIA'))");

        foreach (['unite_article', 'kit_assemble'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('proprietaire', 8)->default('GREENO');
                $t->string('etat', 12)->default('bon');
                $t->foreignId('emplacement_id')->nullable()->constrained('emplacement');
                $t->index('emplacement_id');
            });
            DB::statement("ALTER TABLE {$table}
                ADD CONSTRAINT {$table}_proprietaire CHECK (proprietaire IN ('GREENO', 'TEVIA')),
                ADD CONSTRAINT {$table}_etat CHECK (etat IN ('bon', 'defectueux'))");
        }

        // « En transit » entre deux sites.
        DB::statement('ALTER TABLE unite_article DROP CONSTRAINT unite_article_statut');
        DB::statement("ALTER TABLE unite_article ADD CONSTRAINT unite_article_statut CHECK (statut IN ('en_stock', 'assemble', 'epuise', 'expedie', 'rebute', 'retourne', 'perdu', 'en_transit'))");
        DB::statement('ALTER TABLE kit_assemble DROP CONSTRAINT kit_assemble_statut');
        DB::statement("ALTER TABLE kit_assemble ADD CONSTRAINT kit_assemble_statut CHECK (statut IN ('assemble', 'desassemble', 'expedie', 'en_transit'))");

        // Scission d'un lot quand une partie seulement change d'emplacement.
        DB::statement('ALTER TABLE mouvement_stock DROP CONSTRAINT mouvement_stock_type');
        DB::statement("ALTER TABLE mouvement_stock ADD CONSTRAINT mouvement_stock_type CHECK (type IN ('entree', 'consommation', 'restitution', 'sortie', 'ajustement', 'scission'))");

        // RG28 + RG29, pour les unités comme pour les kits.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION trg_regles_emplacement() RETURNS trigger AS $$
            DECLARE
                v_vente boolean;
                v_espace text;
                v_nom text;
            BEGIN
                IF NEW.emplacement_id IS NULL THEN
                    RETURN NEW;
                END IF;
                SELECT vente, espace, nom INTO v_vente, v_espace, v_nom FROM emplacement WHERE id = NEW.emplacement_id;
                IF NEW.etat = 'defectueux' AND v_vente THEN
                    RAISE EXCEPTION 'RG28 : un élément défectueux ne peut pas être placé dans l''emplacement de vente « % »', v_nom
                        USING ERRCODE = 'check_violation';
                END IF;
                IF v_espace IS NOT NULL AND v_espace <> NEW.proprietaire THEN
                    RAISE EXCEPTION 'RG29 : l''emplacement « % » est réservé au stock %', v_nom, v_espace
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER unite_article_regles_emplacement
                BEFORE INSERT OR UPDATE OF emplacement_id, etat, proprietaire ON unite_article
                FOR EACH ROW EXECUTE FUNCTION trg_regles_emplacement();

            CREATE TRIGGER kit_assemble_regles_emplacement
                BEFORE INSERT OR UPDATE OF emplacement_id, etat, proprietaire ON kit_assemble
                FOR EACH ROW EXECUTE FUNCTION trg_regles_emplacement();
        SQL);

        Schema::create('transfert', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_origine_id')->constrained('site');
            $table->foreignId('site_destination_id')->constrained('site');
            $table->string('statut', 16)->default('en_transit');
            $table->text('motif');
            $table->foreignId('envoye_par')->constrained('users');
            $table->timestamp('envoye_le');
            $table->foreignId('recu_par')->nullable()->constrained('users');
            $table->timestamp('recu_le')->nullable();
            $table->timestamps();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE transfert
                ADD CONSTRAINT transfert_statut CHECK (statut IN ('en_transit', 'recu_partiel', 'recu')),
                ADD CONSTRAINT transfert_sites CHECK (site_origine_id <> site_destination_id)
        SQL);

        Schema::create('transfert_ligne', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfert_id')->constrained('transfert')->cascadeOnDelete();
            $table->foreignId('unite_id')->nullable()->constrained('unite_article');
            $table->foreignId('kit_id')->nullable()->constrained('kit_assemble');
            $table->foreignId('emplacement_depart_id')->constrained('emplacement');
            $table->foreignId('emplacement_arrivee_id')->nullable()->constrained('emplacement');
            $table->timestamp('recu_le')->nullable();
            $table->foreignId('recu_par')->nullable()->constrained('users');
        });
        DB::statement('ALTER TABLE transfert_ligne ADD CONSTRAINT transfert_ligne_cible CHECK (num_nonnulls(unite_id, kit_id) = 1)');

        Schema::create('mouvement_emplacement', function (Blueprint $table) {
            $table->id();
            $table->timestamp('horodatage')->useCurrent();
            $table->string('type', 16);
            $table->foreignId('unite_id')->nullable()->constrained('unite_article');
            $table->foreignId('kit_id')->nullable()->constrained('kit_assemble');
            $table->foreignId('de_emplacement_id')->nullable()->constrained('emplacement');
            $table->foreignId('vers_emplacement_id')->nullable()->constrained('emplacement');
            $table->foreignId('transfert_id')->nullable()->constrained('transfert');
            $table->text('motif')->nullable();
            $table->foreignId('utilisateur_id')->constrained('users');
            $table->index(['unite_id', 'horodatage']);
            $table->index(['kit_id', 'horodatage']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE mouvement_emplacement
                ADD CONSTRAINT mouvement_emplacement_type CHECK (type IN ('affectation', 'deplacement', 'envoi', 'reception', 'cession')),
                ADD CONSTRAINT mouvement_emplacement_cible CHECK (num_nonnulls(unite_id, kit_id) = 1),
                ADD CONSTRAINT mouvement_emplacement_motif CHECK (type NOT IN ('deplacement', 'cession') OR motif IS NOT NULL)
        SQL);

        Schema::create('declaration_etat', function (Blueprint $table) {
            $table->id();
            $table->timestamp('horodatage')->useCurrent();
            $table->string('type', 20);
            $table->foreignId('unite_id')->nullable()->constrained('unite_article');
            $table->foreignId('kit_id')->nullable()->constrained('kit_assemble');
            $table->text('description');
            $table->foreignId('utilisateur_id')->constrained('users');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE declaration_etat
                ADD CONSTRAINT declaration_etat_type CHECK (type IN ('defaut', 'reparation', 'reconditionnement')),
                ADD CONSTRAINT declaration_etat_cible CHECK (num_nonnulls(unite_id, kit_id) = 1)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('declaration_etat');
        Schema::dropIfExists('mouvement_emplacement');
        Schema::dropIfExists('transfert_ligne');
        Schema::dropIfExists('transfert');
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS unite_article_regles_emplacement ON unite_article;
            DROP TRIGGER IF EXISTS kit_assemble_regles_emplacement ON kit_assemble;
            DROP FUNCTION IF EXISTS trg_regles_emplacement();
        SQL);
        foreach (['unite_article', 'kit_assemble'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('emplacement_id');
                $t->dropColumn(['proprietaire', 'etat']);
            });
        }
        DB::statement('ALTER TABLE unite_article DROP CONSTRAINT unite_article_statut');
        DB::statement("ALTER TABLE unite_article ADD CONSTRAINT unite_article_statut CHECK (statut IN ('en_stock', 'assemble', 'epuise', 'expedie', 'rebute', 'retourne', 'perdu'))");
        DB::statement('ALTER TABLE kit_assemble DROP CONSTRAINT kit_assemble_statut');
        DB::statement("ALTER TABLE kit_assemble ADD CONSTRAINT kit_assemble_statut CHECK (statut IN ('assemble', 'desassemble', 'expedie'))");
        DB::statement('ALTER TABLE mouvement_stock DROP CONSTRAINT mouvement_stock_type');
        DB::statement("ALTER TABLE mouvement_stock ADD CONSTRAINT mouvement_stock_type CHECK (type IN ('entree', 'consommation', 'restitution', 'sortie', 'ajustement'))");
        Schema::table('reception', fn (Blueprint $t) => $t->dropColumn('espace'));
        Schema::dropIfExists('emplacement');
        Schema::dropIfExists('site');
    }
};
