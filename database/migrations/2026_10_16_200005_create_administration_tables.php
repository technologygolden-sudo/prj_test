<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Administration : paramètres société (RG15), journal d'audit en ajout seul (ENF05, RG20)
 * et colonnes ajoutées aux tables de spatie/laravel-permission (dossier v2, section 7.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parametre_societe', function (Blueprint $table) {
            $table->smallInteger('id')->primary();
            $table->string('nom_societe', 64);
            $table->string('num_sav', 32);
            $table->string('logo_chemin')->nullable();
            $table->foreignId('modifie_par')->nullable()->constrained('users');
            $table->timestamp('modifie_le')->nullable();
        });
        DB::statement('ALTER TABLE parametre_societe ADD CONSTRAINT parametre_societe_ligne_unique CHECK (id = 1)');

        Schema::create('journal_audit', function (Blueprint $table) {
            $table->id();
            $table->timestamp('horodatage')->useCurrent();
            $table->foreignId('utilisateur_id')->nullable()->constrained('users');
            $table->ipAddress('ip')->nullable();
            $table->string('action', 64);
            $table->string('objet_type', 64);
            $table->string('objet_id', 64)->nullable();
            $table->jsonb('valeur_avant')->nullable();
            $table->jsonb('valeur_apres')->nullable();
            $table->index(['objet_type', 'objet_id']);
            $table->index('horodatage');
        });

        // ENF05 : aucune modification ni suppression, pour personne.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION trg_journal_audit_ajout_seul() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'ENF05 : le journal d''audit est en ajout seul'
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER journal_audit_ajout_seul
                BEFORE UPDATE OR DELETE ON journal_audit
                FOR EACH ROW EXECUTE FUNCTION trg_journal_audit_ajout_seul();

            CREATE TRIGGER journal_audit_pas_de_truncate
                BEFORE TRUNCATE ON journal_audit
                FOR EACH STATEMENT EXECUTE FUNCTION trg_journal_audit_ajout_seul();
        SQL);

        $tables = config('permission.table_names');

        Schema::table($tables['roles'], function (Blueprint $table) {
            $table->boolean('systeme')->default(false); // RG16
            $table->boolean('actif')->default(true);
        });

        Schema::table($tables['permissions'], function (Blueprint $table) {
            $table->string('module', 32)->nullable();
            $table->string('libelle')->nullable();
            $table->boolean('sensible')->default(false); // permission ★ : MFA exigée (RG19)
        });

        Schema::table($tables['model_has_roles'], function (Blueprint $table) {
            $table->foreignId('attribue_par')->nullable()->constrained('users');
            $table->timestamp('attribue_le')->nullable()->useCurrent();
        });
    }

    public function down(): void
    {
        $tables = config('permission.table_names');

        Schema::table($tables['model_has_roles'], function (Blueprint $table) {
            $table->dropConstrainedForeignId('attribue_par');
            $table->dropColumn('attribue_le');
        });
        Schema::table($tables['permissions'], function (Blueprint $table) {
            $table->dropColumn(['module', 'libelle', 'sensible']);
        });
        Schema::table($tables['roles'], function (Blueprint $table) {
            $table->dropColumn(['systeme', 'actif']);
        });

        DB::unprepared('DROP TABLE IF EXISTS journal_audit; DROP FUNCTION IF EXISTS trg_journal_audit_ajout_seul();');
        Schema::dropIfExists('parametre_societe');
    }
};
