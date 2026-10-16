<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #111; }
        h1 { font-size: 14pt; margin: 0 0 4pt; }
        h2 { font-size: 11pt; margin: 16pt 0 6pt; }
        p { margin: 0 0 4pt; color: #444; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 0.5pt solid #ccc; padding: 3pt 4pt; text-align: left; }
        th { background: #f1f5f9; }
        .n { text-align: right; }
        .alerte { color: #b45309; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Simulation n° {{ $simulation->id }}</h1>
    <p>Mode {{ $simulation->mode }} · stock {{ $simulation->source_stock }} · {{ \Illuminate\Support\Carbon::parse($simulation->horodatage)->format('d/m/Y H:i') }} · {{ $simulation->utilisateur }}</p>
    <p>Résultat : {{ $simulation->optimalite }} en {{ $simulation->duree_ms }} ms. Simulation sans effet sur le stock réel.</p>

    <h2>Kits</h2>
    <table>
        <tr><th>Kit</th><th class="n">Rang</th><th class="n">Voulu</th><th class="n">Réalisable</th><th class="n">Capacité seul</th><th>Article limitant</th></tr>
        @foreach ($kits as $k)
            <tr><td>{{ $k['libelle'] }}</td><td class="n">{{ $k['rang'] }}</td><td class="n">{{ $k['voulu'] }}</td><td class="n">{{ $k['realisable'] ?? '—' }}</td><td class="n">{{ $k['capacite'] }}</td><td>{{ $k['limitant'] ?? '—' }}</td></tr>
        @endforeach
    </table>

    <h2>Articles</h2>
    <table>
        <tr><th>Article</th><th>Désignation</th><th class="n">Disponible</th><th class="n">Besoin</th><th class="n">Consommé</th><th class="n">Manquant</th><th class="n">Reliquat</th></tr>
        @foreach ($articles as $a)
            <tr><td>{{ $a['reference'] }}</td><td>{{ $a['nom'] }}</td><td class="n">{{ $a['disponible'] }}</td><td class="n">{{ $a['besoin'] }}</td><td class="n">{{ $a['consomme'] }}</td><td class="n {{ $a['manquant'] > 0 ? 'alerte' : '' }}">{{ $a['manquant'] }}</td><td class="n">{{ $a['reliquat'] }}</td></tr>
        @endforeach
    </table>
</body>
</html>
