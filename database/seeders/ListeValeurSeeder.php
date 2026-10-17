<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Valeurs proposées par défaut ; modifiables dans Référentiel › Listes de valeurs. Rejouable. */
class ListeValeurSeeder extends Seeder
{
    public const VALEURS = [
        'type_article' => ['Batterie', 'Panneau solaire', 'Onduleur / contrôleur', 'Éclairage', 'Téléviseur', 'Ventilateur', 'Câble / accessoire', 'Pompe'],
        'motif_deplacement' => ['Contrôle QA/QC', 'Réassort du shop', 'Mise à disposition au Lab', 'Retour en entrepôt', 'Rangement'],
        'motif_defaut' => ['Ne s’allume pas', 'Ne charge pas', 'Casse physique', 'Défaut d’affichage', 'Retour client sous garantie'],
        'motif_reimpression' => ['Étiquette abîmée', 'Étiquette illisible', 'Étiquette perdue', 'Erreur d’impression'],
        'motif_ajustement' => ['Écart d’inventaire', 'Casse constatée', 'Erreur de saisie', 'Vol ou perte'],
    ];

    public function run(): void
    {
        foreach (self::VALEURS as $liste => $valeurs) {
            foreach ($valeurs as $ordre => $libelle) {
                DB::table('liste_valeur')->insertOrIgnore(['liste' => $liste, 'libelle' => $libelle, 'ordre' => $ordre, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
