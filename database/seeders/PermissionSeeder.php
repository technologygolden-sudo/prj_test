<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Catalogue fixe des permissions et rôles par défaut (dossier v2, section 7.1).
 * Rejouable : met à jour l'existant sans retirer les ajustements faits par l'administrateur,
 * sauf pour le rôle système Administrateur qui reçoit toujours tout (RG16).
 */
class PermissionSeeder extends Seeder
{
    /** code => [module, libellé, sensible] */
    public const CATALOGUE = [
        'articles.voir' => ['referentiel', 'Rechercher, consulter', false],
        'articles.gerer' => ['referentiel', 'Gérer articles, catégories, fabricants', false],
        'kits.gerer' => ['referentiel', 'Gérer types de kit et versions', false],
        'kits.verrouiller' => ['referentiel', 'Verrouiller une version', true],
        'reception.creer' => ['stock', 'Réceptionner des articles', false],
        'stock.sortir' => ['stock', 'Expédier, rebuter, retourner', false],
        'stock.ajuster' => ['stock', "Ajuster l'inventaire", true],
        'assemblage.realiser' => ['stock', 'Assembler un kit', false],
        'assemblage.annuler' => ['stock', 'Désassembler un kit', true],
        'etiquettes.imprimer' => ['etiquettes', 'Imprimer une étiquette', false],
        'etiquettes.reimprimer' => ['etiquettes', 'Réimprimer (motif obligatoire)', false],
        'recherche.scanner' => ['etiquettes', 'Rechercher par scan', false],
        'simulation.lancer' => ['simulation', 'Simuler une composition', false],
        'simulation.exporter' => ['simulation', 'Exporter le résultat', false],
        'capacite.consulter' => ['simulation', 'Calculer la capacité max', false],
        'utilisateurs.gerer' => ['administration', 'Gérer les utilisateurs', true],
        'roles.gerer' => ['administration', 'Gérer rôles et permissions', true],
        'parametres.gerer' => ['administration', 'Société, n° SAV, modèles, imprimantes, sécurité', true],
        'audit.consulter' => ['administration', "Consulter le journal d'audit", false],
    ];

    public const ADMINISTRATEUR = 'Administrateur';

    /** Rôles par défaut hors Administrateur (qui reçoit tout le catalogue). */
    public const ROLES = [
        'Gestionnaire référentiel' => [
            'articles.voir', 'articles.gerer', 'kits.gerer', 'kits.verrouiller',
            'recherche.scanner', 'capacite.consulter',
        ],
        'Réceptionnaire' => [
            'articles.voir', 'reception.creer', 'stock.sortir',
            'etiquettes.imprimer', 'etiquettes.reimprimer', 'recherche.scanner',
        ],
        'Assembleur' => [
            'articles.voir', 'assemblage.realiser', 'assemblage.annuler',
            'etiquettes.imprimer', 'etiquettes.reimprimer', 'recherche.scanner',
        ],
        'Planificateur' => [
            'articles.voir', 'recherche.scanner',
            'simulation.lancer', 'simulation.exporter', 'capacite.consulter',
        ],
        'Lecteur' => [
            'articles.voir', 'recherche.scanner', 'capacite.consulter',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::CATALOGUE as $code => [$module, $libelle, $sensible]) {
            Permission::updateOrCreate(
                ['name' => $code, 'guard_name' => 'web'],
                ['module' => $module, 'libelle' => $libelle, 'sensible' => $sensible],
            );
        }

        $admin = Role::updateOrCreate(
            ['name' => self::ADMINISTRATEUR, 'guard_name' => 'web'],
            ['systeme' => true, 'actif' => true],
        );
        $admin->syncPermissions(array_keys(self::CATALOGUE));

        foreach (self::ROLES as $nom => $permissions) {
            $role = Role::firstOrCreate(['name' => $nom, 'guard_name' => 'web']);
            if ($role->wasRecentlyCreated) {
                $role->syncPermissions($permissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
