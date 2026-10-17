import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import { Bouton, Carte, Champ, Liste, Saisie, Tableau, classes } from '../../Components/ui';

/**
 * Lit le CSV du modèle (référence;désignation;quantité) ou un CSV simple (référence;quantité).
 * La quantité est la dernière colonne numérique ; la ligne d'en-tête est ignorée.
 */
function lireCsv(texte, articles) {
    const parReference = Object.fromEntries(articles.map((a) => [a.reference.toUpperCase(), a.id]));
    const stock = {};
    const inconnues = [];
    for (const ligne of texte.replace(/^﻿/, '').split(/\r?\n/)) {
        const colonnes = ligne.split(/[;\t]/).map((x) => x?.trim().replace(/^"|"$/g, ''));
        const reference = colonnes[0];
        const quantite = [...colonnes].reverse().find((c) => c !== '' && !Number.isNaN(Number(c.replace(',', '.'))));
        if (!reference || quantite === undefined || colonnes.length < 2) continue;
        const id = parReference[reference.toUpperCase()];
        if (id) stock[id] = Math.max(0, Math.round(Number(quantite.replace(',', '.'))));
        else if (!/^r[ée]f/i.test(reference)) inconnues.push(reference);
    }
    return { stock, inconnues };
}

/** Modèle CSV pré-rempli, ouvrable dans Excel (séparateur « ; », UTF-8 avec BOM). */
function telechargerModele(articles, stock) {
    const echapper = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const lignes = [['Référence CRM', 'Désignation', 'Quantité'], ...articles.map((a) => [a.reference, a.nom, stock[a.id] ?? 0])];
    const contenu = '﻿' + lignes.map((l) => l.map(echapper).join(';')).join('\r\n');
    const lien = document.createElement('a');
    lien.href = URL.createObjectURL(new Blob([contenu], { type: 'text/csv;charset=utf-8' }));
    lien.download = 'modele-stock-simulation.csv';
    lien.click();
    URL.revokeObjectURL(lien.href);
}

export default function Creer({ kits, articles, stockReel }) {
    const { data, setData, post, transform, processing, errors } = useForm({
        mode: 'priorite',
        source_stock: 'reel',
        demandes: kits.map((k) => ({ version_id: k.version_id, qte: 0, rang: 1 })),
        stock: Object.fromEntries(articles.map((a) => [a.id, stockReel[a.id] ?? 0])),
    });
    const [onglet, setOnglet] = useState('kits');
    const [importInfo, setImportInfo] = useState(null);
    const [filtre, setFiltre] = useState('');
    const [categorie, setCategorie] = useState('');
    const [choisisSeulement, setChoisisSeulement] = useState(false);
    const categories = [...new Set(kits.map((k) => k.categorie).filter(Boolean))].sort();

    const choisi = (i) => Number(data.demandes[i].qte) > 0;
    const visible = (k, i) =>
        choisisSeulement ? choisi(i) : (!categorie || k.categorie === categorie) && (!filtre || k.libelle.toLowerCase().includes(filtre.toLowerCase()));
    const choisis = kits.map((k, i) => ({ k, i })).filter(({ i }) => choisi(i));
    const changerDemande = (i, champs) => setData('demandes', data.demandes.map((d, j) => (j === i ? { ...d, ...champs } : d)));
    // Seuls les articles des kits choisis comptent pour le stock.
    const utiles = new Set(choisis.flatMap(({ k }) => k.articles));
    const articlesUtiles = articles.filter((a) => utiles.has(a.id));
    const stockAffiche = data.source_stock === 'reel' ? stockReel : data.stock;

    const importer = async (fichier) => {
        const { stock, inconnues } = lireCsv(await fichier.text(), articles);
        setData('stock', { ...Object.fromEntries(articles.map((a) => [a.id, 0])), ...stock });
        setImportInfo(`${Object.keys(stock).length} article(s) importé(s)${inconnues.length ? `, références inconnues ignorées : ${inconnues.join(', ')}` : ''}.`);
    };

    const ongletBouton = (cle, libelle) => (
        <button
            type="button"
            role="tab"
            aria-selected={onglet === cle}
            onClick={() => setOnglet(cle)}
            className={classes('-mb-px border-b-2 px-4 py-2 text-sm font-medium', onglet === cle ? 'border-green-800 text-green-900' : 'border-transparent text-slate-600 hover:text-slate-900')}
        >
            {libelle}
        </button>
    );

    return (
        <AppLayout titre="Nouvelle simulation">
            <div className="mb-4 flex gap-1 border-b border-slate-200" role="tablist">
                {ongletBouton('kits', `1. Kits à constituer (${choisis.length})`)}
                {ongletBouton('parametres', '2. Paramétrage de la simulation')}
            </div>

            <form
                className="space-y-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    transform((d) => ({ ...d, demandes: d.demandes.filter((x) => Number(x.qte) > 0) }));
                    post('/simulations');
                }}
            >
                {onglet === 'kits' && (
                    <Carte>
                        {kits.length === 0 ? (
                            <p className="text-sm text-slate-600">Aucun type de kit n’a encore de nomenclature.</p>
                        ) : (
                            <>
                                <div className="mb-3 flex flex-wrap items-center gap-3">
                                    <Saisie type="search" value={filtre} onChange={(e) => setFiltre(e.target.value)} placeholder="Rechercher un kit" className="max-w-xs" aria-label="Rechercher un kit" />
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
                                    <label className="flex items-center gap-2 text-sm text-slate-700">
                                        <input type="checkbox" checked={choisisSeulement} onChange={(e) => setChoisisSeulement(e.target.checked)} />
                                        Seulement les kits choisis
                                    </label>
                                    <span className="ml-auto text-sm text-slate-600">
                                        {choisis.length} choisi(s) sur {kits.length}
                                    </span>
                                </div>
                                <Tableau colonnes={[{ libelle: '' }, { libelle: 'Kit' }, { libelle: 'Catégorie' }, { libelle: 'Articles', droite: true }]}>
                                    {kits.map((k, i) =>
                                        !visible(k, i) ? null : (
                                            <tr key={k.version_id} className={classes('cursor-pointer hover:bg-slate-50', choisi(i) && 'bg-green-50/60')} onClick={() => changerDemande(i, { qte: choisi(i) ? 0 : 1 })}>
                                                <td className="w-10 px-5 py-1.5">
                                                    <input type="checkbox" readOnly checked={choisi(i)} aria-label={`Constituer ${k.libelle}`} />
                                                </td>
                                                <td className="px-5 py-1.5">{k.libelle}</td>
                                                <td className="px-5 py-1.5 text-slate-600">{k.categorie ?? '—'}</td>
                                                <td className="px-5 py-1.5 text-right tabular-nums">{k.articles.length}</td>
                                            </tr>
                                        ),
                                    )}
                                </Tableau>
                            </>
                        )}
                        <div className="mt-4 flex justify-end">
                            <Bouton disabled={choisis.length === 0} onClick={() => setOnglet('parametres')}>
                                Paramétrer la simulation
                            </Bouton>
                        </div>
                    </Carte>
                )}

                {onglet === 'parametres' && (
                    <>
                        <Carte titre="Quantités et priorités">
                            {choisis.length === 0 ? (
                                <p className="text-sm text-slate-600">Choisissez d’abord les kits à constituer dans le 1er onglet.</p>
                            ) : (
                                <Tableau colonnes={[{ libelle: 'Kit' }, { libelle: 'Quantité voulue' }, { libelle: 'Rang de priorité' }, { libelle: '' }]}>
                                    {choisis.map(({ k, i }) => (
                                        <tr key={k.version_id}>
                                            <td className="px-5 py-1.5">{k.libelle}</td>
                                            <td className="px-5 py-1.5">
                                                <div className="w-28">
                                                    <Saisie type="number" min={1} value={data.demandes[i].qte} onChange={(e) => changerDemande(i, { qte: e.target.value })} aria-label={`Quantité ${k.libelle}`} />
                                                </div>
                                            </td>
                                            <td className="px-5 py-1.5">
                                                <div className="w-24">
                                                    <Saisie type="number" min={1} value={data.demandes[i].rang} onChange={(e) => changerDemande(i, { rang: e.target.value })} aria-label={`Rang ${k.libelle}`} />
                                                </div>
                                            </td>
                                            <td className="px-5 py-1.5 text-right">
                                                <Bouton variante="discret" onClick={() => changerDemande(i, { qte: 0 })}>
                                                    Retirer
                                                </Bouton>
                                            </td>
                                        </tr>
                                    ))}
                                </Tableau>
                            )}
                            <p className="mt-3 text-xs text-slate-500">Rang 1 = le plus urgent.</p>
                            {errors.demandes && <p className="mt-2 text-sm text-red-700">{errors.demandes}</p>}
                        </Carte>

                        <Carte titre="Stock d’articles">
                            <div className="mb-4 grid gap-4 sm:grid-cols-2">
                                <Champ libelle="Source du stock">
                                    <Liste value={data.source_stock} onChange={(e) => setData('source_stock', e.target.value)}>
                                        <option value="reel">Stock réel (lecture seule)</option>
                                        <option value="saisie">Saisie manuelle</option>
                                        <option value="import">Import CSV</option>
                                    </Liste>
                                </Champ>
                                {data.source_stock === 'import' && (
                                    <div className="space-y-2">
                                        <Champ libelle="Fichier CSV" aide={importInfo ?? 'Remplissez la colonne Quantité du modèle, puis chargez-le ici.'}>
                                            <input type="file" accept=".csv,text/csv,text/plain" onChange={(e) => e.target.files[0] && importer(e.target.files[0])} className="text-sm" />
                                        </Champ>
                                        <Bouton variante="secondaire" disabled={articlesUtiles.length === 0} onClick={() => telechargerModele(articlesUtiles, data.stock)}>
                                            Télécharger le modèle CSV
                                        </Bouton>
                                    </div>
                                )}
                            </div>
                            <Tableau
                                colonnes={[{ libelle: 'Référence CRM' }, { libelle: 'Désignation' }, { libelle: 'Quantité disponible' }]}
                                vide={articlesUtiles.length === 0 ? 'Choisissez d’abord des kits : leurs articles apparaîtront ici.' : null}
                            >
                                {articlesUtiles.map((a) => (
                                    <tr key={a.id}>
                                        <td className="px-5 py-1.5 font-medium">{a.reference}</td>
                                        <td className="px-5 py-1.5">{a.nom}</td>
                                        <td className="px-5 py-1.5">
                                            {data.source_stock === 'reel' ? (
                                                <span className="tabular-nums">{stockAffiche[a.id] ?? 0}</span>
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

                        <div className="flex gap-2">
                            <Bouton variante="secondaire" onClick={() => setOnglet('kits')}>
                                Retour aux kits
                            </Bouton>
                            <Bouton type="submit" disabled={processing || choisis.length === 0}>
                                {processing ? 'Calcul en cours…' : 'Lancer la simulation'}
                            </Bouton>
                        </div>
                    </>
                )}
            </form>
        </AppLayout>
    );
}
