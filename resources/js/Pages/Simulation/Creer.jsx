import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import { Bouton, Carte, Champ, Liste, Saisie, Tableau } from '../../Components/ui';

/** Lit un CSV « référence;quantité » (séparateur ; ou ,) et renvoie { article_id: quantité }. */
function lireCsv(texte, articles) {
    const parReference = Object.fromEntries(articles.map((a) => [a.reference.toUpperCase(), a.id]));
    const stock = {};
    const inconnues = [];
    for (const ligne of texte.split(/\r?\n/)) {
        const [reference, quantite] = ligne.split(/[;,\t]/).map((x) => x?.trim());
        if (!reference || !quantite || Number.isNaN(Number(quantite))) continue;
        const id = parReference[reference.replace(/^﻿/, '').toUpperCase()];
        if (id) stock[id] = Math.max(0, Math.round(Number(quantite)));
        else inconnues.push(reference);
    }
    return { stock, inconnues };
}

export default function Creer({ kits, articles, stockReel }) {
    const { data, setData, post, transform, processing, errors } = useForm({
        mode: 'priorite',
        source_stock: 'reel',
        demandes: kits.map((k) => ({ version_id: k.version_id, qte: 0, rang: 1 })),
        stock: Object.fromEntries(articles.map((a) => [a.id, stockReel[a.id] ?? 0])),
    });
    const [importInfo, setImportInfo] = useState(null);
    const [filtre, setFiltre] = useState('');
    const [categorie, setCategorie] = useState('');
    const categories = [...new Set(kits.map((k) => k.categorie).filter(Boolean))].sort();
    const [choisisSeulement, setChoisisSeulement] = useState(false);
    const choisi = (i) => Number(data.demandes[i].qte) > 0;
    const visible = (k, i) =>
        choisisSeulement
            ? choisi(i)
            : choisi(i) || ((!categorie || k.categorie === categorie) && (!filtre || k.libelle.toLowerCase().includes(filtre.toLowerCase())));

    const demandesActives = data.demandes.filter((d) => Number(d.qte) > 0);
    // Seuls les articles des kits demandés comptent pour le stock.
    const utiles = new Set(kits.filter((_, i) => Number(data.demandes[i].qte) > 0).flatMap((k) => k.articles));
    const articlesUtiles = articles.filter((a) => utiles.has(a.id));
    const changerDemande = (i, champs) => setData('demandes', data.demandes.map((d, j) => (j === i ? { ...d, ...champs } : d)));

    const importer = async (fichier) => {
        const { stock, inconnues } = lireCsv(await fichier.text(), articles);
        setData('stock', { ...Object.fromEntries(articles.map((a) => [a.id, 0])), ...stock });
        setImportInfo(`${Object.keys(stock).length} article(s) importé(s)${inconnues.length ? `, ignorés : ${inconnues.join(', ')}` : ''}.`);
    };

    return (
        <AppLayout titre="Nouvelle simulation">
            <form
                className="space-y-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    transform((d) => ({ ...d, demandes: d.demandes.filter((x) => Number(x.qte) > 0) }));
                    post('/simulations');
                }}
            >
                <Carte titre="Kits voulus">
                    {kits.length === 0 ? (
                        <p className="text-sm text-slate-600">Aucun type de kit n’a encore de nomenclature.</p>
                    ) : (
                        <>
                        <div className="mb-3 flex flex-wrap gap-3">
                            <Saisie type="search" value={filtre} onChange={(e) => setFiltre(e.target.value)} placeholder="Filtrer les kits" className="max-w-xs" aria-label="Filtrer les kits" />
                            {categories.length > 0 && (
                                <Liste value={categorie} onChange={(e) => setCategorie(e.target.value)} className="max-w-56" aria-label="Catégorie">
                                    <option value="">Toutes catégories</option>
                                    {categories.map((c) => (
                                        <option key={c} value={c}>
                                            {c}
                                        </option>
                                    ))}
                                </Liste>
                            )}
                            <label className="flex items-center gap-2 self-center text-sm text-slate-700">
                                <input type="checkbox" checked={choisisSeulement} onChange={(e) => setChoisisSeulement(e.target.checked)} />
                                Seulement les kits choisis
                            </label>
                            <span className="self-center text-sm text-slate-600">
                                {demandesActives.length} kit(s) à construire sur {kits.length}
                            </span>
                        </div>
                        <Tableau colonnes={[{ libelle: 'À construire' }, { libelle: 'Type de kit' }, { libelle: 'Quantité voulue' }, { libelle: 'Rang de priorité' }]}>
                            {kits.map((k, i) => !visible(k, i) ? null : (
                                <tr key={k.version_id} className={choisi(i) ? 'bg-green-50/60' : ''}>
                                    <td className="px-5 py-2">
                                        <input
                                            type="checkbox"
                                            checked={choisi(i)}
                                            onChange={(e) => changerDemande(i, { qte: e.target.checked ? 1 : 0 })}
                                            aria-label={`Construire ${k.libelle}`}
                                        />
                                    </td>
                                    <td className="px-5 py-2">{k.libelle}</td>
                                    <td className="px-5 py-2">
                                        <div className="w-28">
                                            <Saisie type="number" min={0} value={data.demandes[i].qte} onChange={(e) => changerDemande(i, { qte: e.target.value })} aria-label={`Quantité ${k.libelle}`} />
                                        </div>
                                    </td>
                                    <td className="px-5 py-2">
                                        <div className="w-24">
                                            <Saisie type="number" min={1} value={data.demandes[i].rang} onChange={(e) => changerDemande(i, { rang: e.target.value })} aria-label={`Rang ${k.libelle}`} />
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </Tableau>
                        </>
                    )}
                    <p className="mt-3 text-xs text-slate-500">Rang 1 = le plus urgent. Seules les lignes avec une quantité sont simulées.</p>
                    {errors.demandes && <p className="mt-2 text-sm text-red-700">{errors.demandes}</p>}
                </Carte>

                <Carte titre="Stock d’articles">
                    <div className="mb-4 grid gap-4 sm:grid-cols-2">
                        <Champ libelle="Source du stock">
                            <Liste value={data.source_stock} onChange={(e) => setData('source_stock', e.target.value)}>
                                <option value="reel">Stock réel (lecture seule)</option>
                                <option value="saisie">Saisie manuelle</option>
                                <option value="import">Import CSV (référence;quantité)</option>
                            </Liste>
                        </Champ>
                        {data.source_stock === 'import' && (
                            <Champ libelle="Fichier CSV" aide={importInfo ?? 'Une ligne par article : référence;quantité'}>
                                <input type="file" accept=".csv,text/csv,text/plain" onChange={(e) => e.target.files[0] && importer(e.target.files[0])} className="text-sm" />
                            </Champ>
                        )}
                    </div>
                    <Tableau
                        colonnes={[{ libelle: 'Article' }, { libelle: 'Désignation' }, { libelle: 'Quantité disponible' }]}
                        vide={articlesUtiles.length === 0 ? 'Indiquez d’abord une quantité pour au moins un kit : ses articles apparaîtront ici.' : null}
                    >
                        {articlesUtiles.map((a) => (
                            <tr key={a.id}>
                                <td className="px-5 py-2 font-medium">{a.reference}</td>
                                <td className="px-5 py-2">{a.nom}</td>
                                <td className="px-5 py-2">
                                    {data.source_stock === 'reel' ? (
                                        <span className="tabular-nums">{stockReel[a.id] ?? 0}</span>
                                    ) : (
                                        <div className="w-28">
                                            <Saisie type="number" min={0} value={data.stock[a.id] ?? 0} onChange={(e) => setData('stock', { ...data.stock, [a.id]: e.target.value })} aria-label={`Stock ${a.reference}`} />
                                        </div>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </Tableau>
                </Carte>

                <Carte titre="Objectif">
                    <Champ libelle="Mode" erreur={errors.mode} className="max-w-md">
                        <Liste value={data.mode} onChange={(e) => setData('mode', e.target.value)}>
                            <option value="priorite">Priorité : servir d’abord les rangs les plus urgents</option>
                            <option value="equilibre">Équilibré : ne sacrifier aucun type de kit</option>
                            <option value="volume">Volume : le plus grand nombre de kits</option>
                        </Liste>
                    </Champ>
                    {errors.mode && <p className="mt-2 text-sm text-red-700">{errors.mode}</p>}
                </Carte>

                <Bouton type="submit" disabled={processing || demandesActives.length === 0}>
                    {processing ? 'Calcul en cours…' : 'Lancer la simulation'}
                </Bouton>
            </form>
        </AppLayout>
    );
}
