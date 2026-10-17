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
        'stock.sortir' => ['stock', 'Expédier (depuis un shop), rebuter, retourner', false],
        'stock.ajuster' => ['stock', "Ajuster l'inventaire", true],
        'stock.deplacer' => ['stock', 'Déplacer entre emplacements d’un site (avec motif)', false],
        'stock.ceder' => ['stock', 'Céder du stock entre GREENO et TEVIA', true],
        'transferts.envoyer' => ['stock', 'Envoyer un transfert vers un autre site', false],
        'transferts.recevoir' => ['stock', 'Valider la réception d’un transfert', false],
        'inventaire.compter' => ['inventaire', 'Compter lors d’un inventaire', false],
        'inventaire.gerer' => ['inventaire', 'Créer et piloter les campagnes d’inventaire', false],
        'inventaire.cloturer' => ['inventaire', 'Clôturer un inventaire et ajuster le stock', true],
        'sav.declarer' => ['sav', 'Déclarer un défaut (SAV)', false],
        'lab.reparer' => ['sav', 'Déclarer une réparation ou un reconditionnement (Lab)', false],
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
            'articles.voir', 'reception.creer', 'stock.sortir', 'stock.deplacer', 'inventaire.compter',
            'transferts.envoyer', 'transferts.recevoir',
            'etiquettes.imprimer', 'etiquettes.reimprimer', 'recherche.scanner',
        ],
        'Technicien Lab' => [
            'articles.voir', 'recherche.scanner', 'stock.deplacer', 'lab.reparer',
            'etiquettes.imprimer', 'etiquettes.reimprimer',
        ],
        'Agent SAV' => [
            'articles.voir', 'recherche.scanner', 'stock.deplacer', 'sav.declarer',
            'transferts.envoyer', 'transferts.recevoir', 'etiquettes.imprimer',
        ],
        'Shop manager' => [
            'articles.voir', 'recherche.scanner', 'stock.deplacer', 'stock.sortir', 'sav.declarer',
            'transferts.envoyer', 'transferts.recevoir', 'etiquettes.imprimer', 'etiquettes.reimprimer',
            'inventaire.compter',
        ],
        'Contrôleur de gestion' => [
            'articles.voir', 'recherche.scanner', 'capacite.consulter', 'audit.consulter',
            'inventaire.compter', 'inventaire.gerer', 'inventaire.cloturer',
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

        $nouvelles = [];
        foreach (self::CATALOGUE as $code => [$module, $libelle, $sensible]) {
            $permission = Permission::updateOrCreate(
                ['name' => $code, 'guard_name' => 'web'],
                ['module' => $module, 'libelle' => $libelle, 'sensible' => $sensible],
            );
            if ($permission->wasRecentlyCreated) {
                $nouvelles[] = $code;
            }
        }

        $admin = Role::updateOrCreate(
            ['name' => self::ADMINISTRATEUR, 'guard_name' => 'web'],
            ['systeme' => true, 'actif' => true],
        );
        $admin->syncPermissions(array_keys(self::CATALOGUE));

        // Ancien nom du rôle de gestion d'un centre de service.
        Role::where(['name' => 'Responsable centre', 'guard_name' => 'web'])->update(['name' => 'Shop manager']);

        foreach (self::ROLES as $nom => $permissions) {
            $role = Role::firstOrCreate(['name' => $nom, 'guard_name' => 'web']);
            if ($role->wasRecentlyCreated) {
                $role->syncPermissions($permissions);
            } elseif ($ajouts = array_intersect($permissions, $nouvelles)) {
                // Rôle déjà ajusté par l'administrateur : on ne lui ajoute que les permissions apparues depuis.
                $role->givePermissionTo($ajouts);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
