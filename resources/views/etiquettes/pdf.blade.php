<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #000; }
        .etiquette { position: relative; width: 50mm; height: 30mm; padding: 2.5mm; page-break-after: always; overflow: hidden; }
        .etiquette:last-child { page-break-after: auto; }
        .entete { height: 3.2mm; border-bottom: 0.2mm solid #000; font-size: 6.5pt; }
        .societe { float: left; font-weight: bold; }
        .sav { float: right; font-size: 5pt; }
        .libelle { position: absolute; top: 6.4mm; left: 2.5mm; width: 33mm; font-size: 7pt; font-weight: bold; line-height: 1.1; max-height: 5.6mm; overflow: hidden; }
        .reference { position: absolute; top: 12.3mm; left: 2.5mm; width: 33mm; font-size: 5.5pt; }
        .qr { position: absolute; top: 6.4mm; right: 2.5mm; width: 9.4mm; height: 9.4mm; }
        .code { position: absolute; top: 16.6mm; left: 2.5mm; width: 35mm; height: 7mm; }
        .pied { position: absolute; bottom: 2.3mm; left: 2.5mm; right: 2.5mm; text-align: center; font-size: 6.5pt; font-weight: bold; }
    </style>
</head>
<body>
@foreach ($pages as $p)
    <div class="etiquette">
        <div class="entete"><span class="societe">{{ $p['c']->societe }}</span><span class="sav">{{ $p['c']->sav }}</span></div>
        <div class="libelle">{{ $p['c']->libelle }}</div>
        <div class="reference">{{ $p['c']->reference }}</div>
        <img class="qr" src="{{ $p['qr'] }}" alt="">
        <img class="code" src="{{ $p['code'] }}" alt="">
        <div class="pied">{{ $p['c']->pied }}</div>
    </div>
@endforeach
</body>
</html>
