<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel : catégories, fabricants, articles, types de kit et versions de nomenclature.
 * Règles portées en base : RG01, RG02, RG03, RG05 et RG10 (dossier de conception v2, section 2.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorie', function (Blueprint $table) {
            $table->id();
            $table->string('libelle')->unique();
            $table->timestamps();
        });

        Schema::create('fabricant', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->unique();
            $table->timestamps();
        });

        Schema::create('article', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique(); // RG01
            $table->string('nom');
            $table->string('type', 64)->nullable(); // valeurs à définir (dossier v2, 2.1)
            $table->boolean('est_serialise')->default(false); // RG04
            $table->foreignId('categorie_id')->nullable()->constrained('categorie');
            $table->foreignId('fabricant_id')->nullable()->constrained('fabricant');
            $table->timestamps();
        });

        Schema::create('article_associe', function (Blueprint $table) {
            $table->foreignId('article_id')->constrained('article')->cascadeOnDelete();
            $table->foreignId('article_associe_id')->constrained('article')->cascadeOnDelete();
            $table->primary(['article_id', 'article_associe_id']);
        });
        DB::statement('ALTER TABLE article_associe ADD CONSTRAINT article_associe_distinct CHECK (article_id <> article_associe_id)');

        Schema::create('type_kit', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique(); // RG02
            $table->string('partno', 64);
            $table->string('nom');
            $table->foreignId('categorie_id')->nullable()->constrained('categorie');
            $table->foreignId('fabricant_id')->nullable()->constrained('fabricant');
            $table->timestamps();
        });

        Schema::create('version_kit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('type_kit_id')->constrained('type_kit');
            $table->unsignedInteger('numero');
            $table->boolean('verrouillee')->default(false);
            $table->timestamp('verrouillee_le')->nullable();
            $table->foreignId('verrouillee_par')->nullable()->constrained('users');
            $table->foreignId('article_porteur_id')->constrained('article');
            $table->timestamps();
            $table->unique(['type_kit_id', 'numero']); // RG10
        });

        Schema::create('composition', function (Blueprint $table) {
            $table->foreignId('version_kit_id')->constrained('version_kit')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('article');
            $table->unsignedInteger('quantite');
            $table->primary(['version_kit_id', 'article_id']);
        });
        DB::statement('ALTER TABLE composition ADD CONSTRAINT composition_quantite_min CHECK (quantite >= 1)'); // RG03

        // RG05 : le porteur figure dans la composition de sa version (vérifié à la validation de la transaction).
        DB::statement('ALTER TABLE version_kit ADD CONSTRAINT version_kit_porteur_dans_composition
            FOREIGN KEY (id, article_porteur_id) REFERENCES composition (version_kit_id, article_id)
            DEFERRABLE INITIALLY DEFERRED');

        // RG05 : le porteur est sérialisé et présent avec la quantité 1.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION verifier_porteur_version(p_version_id bigint) RETURNS void AS $$
            DECLARE
                v_porteur bigint;
                v_quantite integer;
                v_serialise boolean;
            BEGIN
                SELECT article_porteur_id INTO v_porteur FROM version_kit WHERE id = p_version_id;
                IF NOT FOUND THEN
                    RETURN;
                END IF;
                SELECT c.quantite, a.est_serialise INTO v_quantite, v_serialise
                FROM composition c JOIN article a ON a.id = c.article_id
                WHERE c.version_kit_id = p_version_id AND c.article_id = v_porteur;
                IF v_quantite IS DISTINCT FROM 1 OR NOT v_serialise THEN
                    RAISE EXCEPTION 'RG05 : le porteur de la version % doit être sérialisé et présent avec la quantité 1', p_version_id
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql;

            CREATE FUNCTION trg_version_kit_porteur() RETURNS trigger AS $$
            BEGIN
                PERFORM verifier_porteur_version(NEW.id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER version_kit_porteur_rg05
                AFTER INSERT OR UPDATE OF article_porteur_id ON version_kit
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION trg_version_kit_porteur();

            CREATE FUNCTION trg_composition_porteur() RETURNS trigger AS $$
            BEGIN
                PERFORM verifier_porteur_version(NEW.version_kit_id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER composition_porteur_rg05
                AFTER INSERT OR UPDATE ON composition
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION trg_composition_porteur();

            CREATE FUNCTION trg_article_serialisation() RETURNS trigger AS $$
            BEGIN
                IF OLD.est_serialise AND NOT NEW.est_serialise
                   AND EXISTS (SELECT 1 FROM version_kit WHERE article_porteur_id = NEW.id) THEN
                    RAISE EXCEPTION 'RG05 : l''article % est porteur de série d''un kit et doit rester sérialisé', NEW.reference
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER article_serialisation_rg05
                BEFORE UPDATE OF est_serialise ON article
                FOR EACH ROW EXECUTE FUNCTION trg_article_serialisation();
        SQL);

        // RG10 : une version verrouillée et sa composition ne sont plus modifiables.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION trg_version_kit_verrou() RETURNS trigger AS $$
            BEGIN
                IF OLD.verrouillee THEN
                    RAISE EXCEPTION 'RG10 : la version % est verrouillée', OLD.id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER version_kit_verrou_rg10
                BEFORE UPDATE OR DELETE ON version_kit
                FOR EACH ROW EXECUTE FUNCTION trg_version_kit_verrou();

            CREATE FUNCTION trg_composition_verrou() RETURNS trigger AS $$
            DECLARE
                v_version bigint;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    v_version := NEW.version_kit_id;
                ELSE
                    v_version := OLD.version_kit_id;
                END IF;
                IF EXISTS (SELECT 1 FROM version_kit WHERE id = v_version AND verrouillee) THEN
                    RAISE EXCEPTION 'RG10 : la composition de la version % est verrouillée', v_version
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER composition_verrou_rg10
                BEFORE INSERT OR UPDATE OR DELETE ON composition
                FOR EACH ROW EXECUTE FUNCTION trg_composition_verrou();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS version_kit CASCADE');
        Schema::dropIfExists('composition');
        Schema::dropIfExists('type_kit');
        Schema::dropIfExists('article_associe');
        Schema::dropIfExists('article');
        Schema::dropIfExists('fabricant');
        Schema::dropIfExists('categorie');
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS trg_composition_verrou();
            DROP FUNCTION IF EXISTS trg_version_kit_verrou();
            DROP FUNCTION IF EXISTS trg_article_serialisation();
            DROP FUNCTION IF EXISTS trg_composition_porteur();
            DROP FUNCTION IF EXISTS trg_version_kit_porteur();
            DROP FUNCTION IF EXISTS verifier_porteur_version(bigint);
        SQL);
    }
};
