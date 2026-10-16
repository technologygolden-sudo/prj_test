<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AccueilController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Accueil', [
            'compteurs' => [
                'articles' => DB::table('article')->count(),
                'types_kit' => DB::table('type_kit')->count(),
                'brouillons' => DB::table('version_kit')->where('verrouillee', false)->count(),
                'unites_en_stock' => DB::table('unite_article')->where('statut', 'en_stock')->count(),
            ],
        ]);
    }
}
