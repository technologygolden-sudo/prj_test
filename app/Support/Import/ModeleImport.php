<?php

namespace App\Support\Import;

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Modèle Excel vierge au format lu par ImportReferentiel : mêmes feuilles et mêmes colonnes,
 * une ligne d'exemple par feuille et une feuille « Aide » qui explique chaque colonne.
 */
class ModeleImport
{
    public const ARTICLES = ['Catégorie', 'Nom', 'Type', 'Fabricant', 'Référence', 'Produits associés', 'Coût unitaire'];

    public const KITS = ['Catégorie', 'Nom', 'Fabricant', 'Verrouillable', 'Référence', 'Parts'];

    public function generer(): string
    {
        $classeur = new Spreadsheet;
        $classeur->getProperties()->setTitle('Modèle d’import du référentiel')->setCreator(config('app.name'));

        $articles = $classeur->getActiveSheet()->setTitle('products_parts');
        $this->entete($articles, self::ARTICLES);
        $articles->fromArray([
            ['Part', 'N°1 Spark Battery 42 wh', 'Sérialisé', 'Spark', 'BAT-SPK-1-01', 'TEVIA ECO, TEVIA CLASSIC', 45000],
            ['Part', 'Spark 2M cable', 'Non sérialisé', 'Spark', 'CBL-SD2-1-02', 'TEVIA ECO', 1500],
        ], null, 'A2');
        $this->listeDeroulante($articles, 'C2:C5000', '"Sérialisé,Non sérialisé"');

        $kits = $classeur->createSheet()->setTitle('products_serialized');
        $this->entete($kits, self::KITS);
        $kits->fromArray([
            ['Energy › TEVIA', 'TEVIA ECO', 'RuralSpark', 'Oui', 'TEVIAECO', 2],
            ['Energy › TEVIA', 'TEVIA CLASSIC', 'RuralSpark', 'Oui', 'TEVIACLASSIC', 1],
        ], null, 'A2');
        $this->listeDeroulante($kits, 'D2:D5000', '"Oui,Non"');

        $aide = $classeur->createSheet()->setTitle('Aide');
        $aide->fromArray([
            ['Feuille', 'Colonne', 'Obligatoire', 'Contenu'],
            ['products_parts', 'Catégorie', 'Non', 'Catégorie de l’article (créée si elle n’existe pas).'],
            ['products_parts', 'Nom', 'Oui', 'Désignation de l’article.'],
            ['products_parts', 'Type', 'Oui', 'Sérialisé (un n° de série par unité) ou Non sérialisé (géré par lot).'],
            ['products_parts', 'Fabricant', 'Oui si sérialisé', 'Le n° de série est unique par fabricant.'],
            ['products_parts', 'Référence', 'Oui', 'Unique, 64 caractères au plus. Sert à retrouver l’article lors des imports suivants.'],
            ['products_parts', 'Produits associés', 'Non', 'Noms des kits qui contiennent l’article, séparés par des virgules (quantité 1).'],
            ['products_parts', 'Coût unitaire', 'Non', 'Coût en FCFA, utilisé pour valoriser les écarts d’inventaire.'],
            ['products_serialized', 'Catégorie', 'Non', 'Catégorie du kit.'],
            ['products_serialized', 'Nom', 'Oui', 'Nom du kit, tel qu’il est écrit dans « Produits associés ».'],
            ['products_serialized', 'Fabricant', 'Non', 'Fabricant du kit.'],
            ['products_serialized', 'Verrouillable', 'Non', 'Information conservée dans le fichier : le verrouillage se fait dans l’application après le choix du porteur.'],
            ['products_serialized', 'Référence', 'Oui', 'Unique, 64 caractères au plus ; sert aussi de PartNo à la création.'],
            ['products_serialized', 'Parts', 'Non', 'Nombre d’articles attendus : un écart avec « Produits associés » est signalé dans l’aperçu.'],
            [],
            ['Après l’import', '', '', 'Les nomenclatures arrivent en brouillon, sans porteur de série. Sur chaque kit : choisir le porteur, ajuster les quantités, puis verrouiller.'],
            ['Imports suivants', '', '', 'Le même fichier peut être réimporté : rien n’est créé en double et une version verrouillée n’est jamais modifiée.'],
        ]);
        $aide->getStyle('A1:D1')->getFont()->setBold(true);
        foreach (['A' => 22, 'B' => 20, 'C' => 18, 'D' => 110] as $colonne => $largeur) {
            $aide->getColumnDimension($colonne)->setWidth($largeur);
        }

        $classeur->setActiveSheetIndex(0);
        $chemin = tempnam(sys_get_temp_dir(), 'modele');
        (new Xlsx($classeur))->save($chemin);
        $contenu = file_get_contents($chemin);
        @unlink($chemin);

        return $contenu;
    }

    private function entete(Worksheet $feuille, array $colonnes): void
    {
        $feuille->fromArray($colonnes, null, 'A1');
        $derniere = chr(ord('A') + count($colonnes) - 1);
        $style = $feuille->getStyle("A1:{$derniere}1");
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('166534');
        $feuille->freezePane('A2');
        foreach (range('A', $derniere) as $colonne) {
            $feuille->getColumnDimension($colonne)->setWidth($colonne === 'F' ? 45 : 24);
        }
    }

    private function listeDeroulante(Worksheet $feuille, string $plage, string $valeurs): void
    {
        $validation = $feuille->getCell(explode(':', $plage)[0])->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)
            ->setShowErrorMessage(true)->setErrorTitle('Valeur non prévue')->setError('Choisissez une valeur dans la liste.')
            ->setFormula1($valeurs)->setSqref($plage);
    }
}
