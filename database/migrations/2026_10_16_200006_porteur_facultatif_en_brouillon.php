<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RG05 assouplie pour les brouillons : une version importée peut attendre que le gestionnaire
 * choisisse son porteur de série. Le porteur reste obligatoire pour verrouiller (et donc assembler).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE version_kit ALTER COLUMN article_porteur_id DROP NOT NULL');
        DB::statement('ALTER TABLE version_kit ADD CONSTRAINT version_kit_porteur_au_verrouillage CHECK (NOT verrouillee OR article_porteur_id IS NOT NULL)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION verifier_porteur_version(p_version_id bigint) RETURNS void AS $$
            DECLARE
                v_porteur bigint;
                v_quantite integer;
                v_serialise boolean;
            BEGIN
                SELECT article_porteur_id INTO v_porteur FROM version_kit WHERE id = p_version_id;
                IF NOT FOUND OR v_porteur IS NULL THEN
                    RETURN; -- brouillon sans porteur : contrôlé au verrouillage par la contrainte CHECK
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
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE version_kit DROP CONSTRAINT IF EXISTS version_kit_porteur_au_verrouillage');
        DB::statement('ALTER TABLE version_kit ALTER COLUMN article_porteur_id SET NOT NULL');
    }
};
