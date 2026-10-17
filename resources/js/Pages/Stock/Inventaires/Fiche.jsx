import { router, useForm } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import AppLayout from '../../../Layouts/AppLayout';
import { postJson } from '../../../Components/http';
import { signal } from '../../../Components/signal';
import { Bouton, Carte, Champ, Liste, Pastille, Saisie, classes, dateFr, usePeut } from '../../../Components/ui';
import { StatutInventaire, fcfa } from './Index';

const etapes = [
    ['brouillon', 'Paramétrage'],
    ['comptage1', '1er comptage'],
    ['analyse', 'Analyse des écarts'],
    ['comptage2', '2e comptage'],
    ['decompte', 'Décompte final'],
    ['cloture', 'Clôture'],
];

function Frise({ inventaire }) {
    const courante = inventaire.statut === 'comptage' ? `comptage${inventaire.tour}` : inventaire.statut;
    const index = etapes.findIndex(([e]) => e === courante);
    return (
        <ol className="flex flex-wrap gap-1 text-xs">
            {etapes.map(([e, l], i) => (
                <li key={e} className={classes('rounded-full px-3 py-1 ring-1', i < index ? 'bg-green-50 text-green-800 ring-green-200' : i === index ? 'bg-green-800 font-semibold text-white ring-green-800' : 'text-slate-500 ring-slate-200')}>
                    {i + 1}. {l}
                </li>
            ))}
        </ol>
    );
}

/** Comptage par scan (n° de série, UID de lot, n° de kit) ou saisie manuelle d'une quantité. */
function Comptage({ inventaire, sites, articles, typesKit, comptes }) {
    const [siteId, setSiteId] = useState(sites[0]?.id ?? '');
    const [code, setCode] = useState('');
    const [quantite, setQuantite] = useState('');
    const [saisie, setSaisie] = useState({ cle: '', quantite: 1 });
    const [journal, setJournal] = useState([]);
    const champ = useRef(null);

    const envoyer = async (corps) => {
        try {
            const r = await postJson(`/inventaires/${inventaire.id}/compter`, { site_id: Number(siteId), ...corps });
            signal.ok();
            setJournal((j) => [{ ok: true, texte: `${r.libelle} : +${r.quantite} (total compté ${r.total})` }, ...j].slice(0, 15));
        } catch (e) {
            signal.erreur();
            setJournal((j) => [{ ok: false, texte: e.message }, ...j].slice(0, 15));
        }
        champ.current?.focus();
    };

    return (
        <Carte titre={`${inventaire.tour === 1 ? '1er' : '2e'} comptage`} actions={<span className="text-sm text-slate-600">{comptes} saisie(s) dans ce tour</span>}>
            {inventaire.tour === 2 && <p className="mb-3 text-sm text-amber-800">Seules les lignes désignées par le contrôleur de gestion sont à recompter.</p>}
            <div className="mb-4 flex flex-wrap items-end gap-3">
                <Champ libelle="Site compté" className="w-64">
                    <Liste value={siteId} onChange={(e) => setSiteId(e.target.value)}>
                        {sites.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.nom}
                            </option>
                        ))}
                    </Liste>
                </Champ>
                <Champ libelle="Scanner le code-barres" className="min-w-64 flex-1">
                    <Saisie
                        ref={champ}
                        autoFocus
                        value={code}
                        onChange={(e) => setCode(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && code.trim()) {
                                e.preventDefault();
                                envoyer({ code: code.trim(), quantite: quantite ? Number(quantite) : null });
                                setCode('');
                                setQuantite('');
                            }
                        }}
                        placeholder="N° de série, UID de lot ou n° de kit"
                    />
                </Champ>
                <Champ libelle="Qté (lot)" className="w-24">
                    <Saisie type="number" min={1} value={quantite} onChange={(e) => setQuantite(e.target.value)} placeholder="—" />
                </Champ>
            </div>
            <details className="mb-4 text-sm">
                <summary className="cursor-pointer text-green-800">Saisir une quantité sans scan (article non étiqueté)</summary>
                <div className="mt-2 flex flex-wrap items-end gap-3">
                    <Champ libelle="Article ou kit" className="min-w-72 flex-1">
                        <Liste value={saisie.cle} onChange={(e) => setSaisie({ ...saisie, cle: e.target.value })}>
                            <option value="">Choisir…</option>
                            <optgroup label="Articles">
                                {articles.map((a) => (
                                    <option key={`a${a.id}`} value={`a:${a.id}`}>
                                        {a.reference} · {a.nom}
                                    </option>
                                ))}
                            </optgroup>
                            {typesKit.length > 0 && (
                                <optgroup label="Kits montés">
                                    {typesKit.map((k) => (
                                        <option key={`k${k.id}`} value={`k:${k.id}`}>
                                            Kit {k.reference} · {k.nom}
                                        </option>
                                    ))}
                                </optgroup>
                            )}
                        </Liste>
                    </Champ>
                    <Champ libelle="Quantité" className="w-28">
                        <Saisie type="number" min={1} value={saisie.quantite} onChange={(e) => setSaisie({ ...saisie, quantite: e.target.value })} />
                    </Champ>
                    <Bouton
                        variante="secondaire"
                        disabled={!saisie.cle}
                        onClick={() => {
                            const [type, id] = saisie.cle.split(':');
                            envoyer({ [type === 'a' ? 'article_id' : 'type_kit_id']: Number(id), quantite: Number(saisie.quantite) });
                            setSaisie({ cle: '', quantite: 1 });
                        }}
                    >
                        Ajouter
                    </Bouton>
                </div>
            </details>
            {journal.length > 0 && (
                <ul className="space-y-0.5 rounded-md bg-slate-50 p-3 text-sm">
                    {journal.map((j, i) => (
                        <li key={i} className={j.ok ? 'text-green-800' : 'text-amber-800'}>
                            {j.texte}
                        </li>
                    ))}
                </ul>
            )}
        </Carte>
    );
}

function LigneFinale({ ligne }) {
    const dernier = ligne.recompter ? ligne.comptage2 : ligne.comptage1;
    const { data, setData, put, processing, isDirty, errors } = useForm({ final: ligne.final ?? dernier ?? 0, justification: ligne.justification ?? '' });
    return (
        <div className="flex items-start gap-2">
            <div className="w-20">
                <Saisie type="number" min={0} value={data.final} onChange={(e) => setData('final', e.target.value)} aria-label="Quantité finale" />
            </div>
            {Number(data.final) !== Number(dernier) && (
                <div className="w-56">
                    <Saisie value={data.justification} onChange={(e) => setData('justification', e.target.value)} placeholder="Justification" aria-label="Justification" invalide={errors.justification} />
                    {errors.justification && <p className="text-xs text-red-700">{errors.justification}</p>}
                </div>
            )}
            {isDirty && (
                <Bouton variante="secondaire" disabled={processing} onClick={() => put(`/inventaires/lignes/${ligne.id}`, { preserveScroll: true })}>
                    OK
                </Bouton>
            )}
        </div>
    );
}

function TableauLignes({ inventaire, lignes, selection, setSelection }) {
    const [filtre, setFiltre] = useState('ecarts');
    const [site, setSite] = useState('');
    const sitesLignes = [...new Set(lignes.map((l) => l.site))];
    const visibles = lignes.filter((l) => (!site || l.site === site) && (filtre === 'toutes' || (filtre === 'ecarts' ? l.ecart !== null && l.ecart !== 0 : l.recompter)));
    const analyse = inventaire.statut === 'analyse';
    const decompte = inventaire.statut === 'decompte';
    const avecComptage = inventaire.tour >= 1 && inventaire.statut !== 'comptage';

    return (
        <section className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
            <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 px-4 py-3">
                <h2 className="mr-auto text-base font-semibold">Lignes ({visibles.length})</h2>
                {sitesLignes.length > 1 && (
                    <Liste value={site} onChange={(e) => setSite(e.target.value)} className="max-w-56" aria-label="Site">
                        <option value="">Tous les sites</option>
                        {sitesLignes.map((s) => (
                            <option key={s} value={s}>
                                {s}
                            </option>
                        ))}
                    </Liste>
                )}
                <Liste value={filtre} onChange={(e) => setFiltre(e.target.value)} className="max-w-48" aria-label="Lignes affichées">
                    <option value="ecarts">Lignes en écart</option>
                    <option value="recompter">Lignes recomptées</option>
                    <option value="toutes">Toutes les lignes</option>
                </Liste>
            </div>
            <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                    <thead className="bg-slate-50 text-left text-slate-600">
                        <tr>
                            {analyse && <th className="w-10 px-4 py-2" />}
                            <th className="px-3 py-2 font-medium">Site</th>
                            <th className="px-3 py-2 font-medium">Élément</th>
                            <th className="px-3 py-2 text-right font-medium">Théorique</th>
                            <th className="px-3 py-2 text-right font-medium">1er compt.</th>
                            <th className="px-3 py-2 text-right font-medium">2e compt.</th>
                            <th className="px-3 py-2 font-medium">Final</th>
                            <th className="px-3 py-2 text-right font-medium">Écart</th>
                            <th className="px-3 py-2 text-right font-medium">Coût unit.</th>
                            <th className="px-3 py-2 text-right font-medium">Valeur écart</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {visibles.map((l) => (
                            <tr key={l.id} className={classes('whitespace-nowrap', selection.includes(l.id) && 'bg-amber-50')}>
                                {analyse && (
                                    <td className="px-4 py-1.5">
                                        <input
                                            type="checkbox"
                                            checked={selection.includes(l.id)}
                                            onChange={() => setSelection(selection.includes(l.id) ? selection.filter((x) => x !== l.id) : [...selection, l.id])}
                                            aria-label={`Recompter ${l.reference}`}
                                        />
                                    </td>
                                )}
                                <td className="px-3 py-1.5 text-slate-600">{l.site}</td>
                                <td className="max-w-72 truncate px-3 py-1.5" title={l.nom}>
                                    <span className="font-medium">{l.reference}</span> <span className="text-slate-500">{l.nom}</span>
                                    {l.recompter && <Pastille couleur="orange">Recompté</Pastille>}
                                </td>
                                <td className="px-3 py-1.5 text-right tabular-nums">{l.theorique}</td>
                                <td className="px-3 py-1.5 text-right tabular-nums">{avecComptage || inventaire.tour === 2 ? (l.comptage1 ?? '—') : '…'}</td>
                                <td className="px-3 py-1.5 text-right tabular-nums">{l.recompter ? (l.comptage2 ?? '…') : '—'}</td>
                                <td className="px-3 py-1.5">{decompte ? <LigneFinale ligne={l} /> : <span className="tabular-nums">{l.final ?? '—'}</span>}</td>
                                <td className={classes('px-3 py-1.5 text-right font-medium tabular-nums', l.ecart < 0 && 'text-red-700', l.ecart > 0 && 'text-green-800')}>
                                    {l.ecart === null ? '—' : l.ecart > 0 ? `+${l.ecart}` : l.ecart}
                                </td>
                                <td className="px-3 py-1.5 text-right tabular-nums text-slate-600">{l.cout_unitaire === null ? <span className="text-amber-700">non renseigné</span> : fcfa(l.cout_unitaire)}</td>
                                <td className={classes('px-3 py-1.5 text-right tabular-nums', Number(l.valeur_ecart) < 0 && 'text-red-700')}>{fcfa(l.valeur_ecart)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {visibles.length === 0 && <p className="px-5 py-8 text-center text-sm text-slate-500">{filtre === 'ecarts' ? 'Aucun écart pour l’instant.' : 'Aucune ligne.'}</p>}
            </div>
        </section>
    );
}

export default function Fiche({ inventaire, sites, perimetre, lignes, inconnus, comptes, articles, typesKit }) {
    const peut = usePeut();
    const enEcart = lignes.filter((l) => l.ecart !== null && l.ecart !== 0);
    const [selection, setSelection] = useState(enEcart.map((l) => l.id));
    const [ajuster, setAjuster] = useState(true);
    const totaux = useMemo(
        () => ({
            pertes: lignes.filter((l) => Number(l.valeur_ecart) < 0).reduce((s, l) => s + Number(l.valeur_ecart), 0),
            gains: lignes.filter((l) => Number(l.valeur_ecart) > 0).reduce((s, l) => s + Number(l.valeur_ecart), 0),
            sansCout: lignes.filter((l) => l.ecart && l.cout_unitaire === null).length,
        }),
        [lignes],
    );
    const action = (url, donnees = {}, confirmation = null) => {
        if (!confirmation || confirm(confirmation)) router.post(url, donnees, { preserveScroll: true });
    };

    return (
        <AppLayout
            titre={`Inventaire n° ${inventaire.id} · ${inventaire.libelle}`}
            actions={
                <>
                    {inventaire.statut === 'brouillon' && peut('inventaire.gerer') && (
                        <>
                            <Bouton variante="secondaire" href={`/inventaires/${inventaire.id}/parametres`}>
                                Paramètres
                            </Bouton>
                            <Bouton onClick={() => action(`/inventaires/${inventaire.id}/lancer`, {}, 'Lancer l’inventaire ? Le stock théorique sera figé.')}>Lancer l’inventaire</Bouton>
                        </>
                    )}
                    {inventaire.statut === 'comptage' && peut('inventaire.gerer') && (
                        <Bouton onClick={() => action(`/inventaires/${inventaire.id}/terminer-comptage`, {}, `Terminer le ${inventaire.tour === 1 ? '1er' : '2e'} comptage ?`)}>
                            Terminer le {inventaire.tour === 1 ? '1er' : '2e'} comptage
                        </Bouton>
                    )}
                    <Bouton variante="secondaire" href="/inventaires">
                        Inventaires
                    </Bouton>
                </>
            }
        >
            <div className="space-y-6">
                <Carte actions={<StatutInventaire inventaire={inventaire} />}>
                    <Frise inventaire={inventaire} />
                    <dl className="mt-4 grid gap-4 text-sm sm:grid-cols-4">
                        <div>
                            <dt className="text-slate-500">Date</dt>
                            <dd className="font-medium">{dateFr(inventaire.date_prevue, false)}</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Sites</dt>
                            <dd className="font-medium">{sites.map((s) => s.nom).join(', ')}</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Périmètre</dt>
                            <dd className="font-medium">{perimetre.join(', ') || '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Lancé le</dt>
                            <dd className="font-medium">{dateFr(inventaire.lance_le)}</dd>
                        </div>
                    </dl>
                    {inventaire.commentaire && <p className="mt-3 text-sm text-slate-600">{inventaire.commentaire}</p>}
                </Carte>

                {inventaire.statut === 'comptage' && peut('inventaire.compter') && <Comptage inventaire={inventaire} sites={sites} articles={articles} typesKit={typesKit} comptes={comptes} />}

                {inventaire.statut === 'analyse' && peut('inventaire.gerer') && (
                    <Carte titre="Analyse du 1er comptage">
                        <p className="mb-3 text-sm text-slate-700">
                            {enEcart.length} ligne(s) en écart sur {lignes.length}. Lancez un 2e comptage sur les lignes cochées, ou passez directement au décompte final.
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <Bouton disabled={selection.length === 0} onClick={() => action(`/inventaires/${inventaire.id}/second-comptage`, { lignes: selection })}>
                                Lancer le 2e comptage ({selection.length} ligne(s))
                            </Bouton>
                            <Bouton variante="secondaire" onClick={() => action(`/inventaires/${inventaire.id}/decompte`, {}, 'Passer au décompte final sans 2e comptage ?')}>
                                Passer au décompte final
                            </Bouton>
                        </div>
                    </Carte>
                )}

                {['decompte', 'cloture'].includes(inventaire.statut) && (
                    <div className="grid gap-4 sm:grid-cols-3">
                        {[
                            ['Écart valorisé net', inventaire.valeur_ecart, Number(inventaire.valeur_ecart) < 0],
                            ['Pertes (manquants)', totaux.pertes, true],
                            ['Gains (excédents)', totaux.gains, false],
                        ].map(([l, v, rouge]) => (
                            <div key={l} className="rounded-lg bg-white p-4 shadow-sm ring-1 ring-slate-200">
                                <p className="text-sm text-slate-600">{l}</p>
                                <p className={classes('mt-1 text-xl font-semibold tabular-nums', rouge && Number(v) < 0 && 'text-red-700')}>{fcfa(v)}</p>
                            </div>
                        ))}
                    </div>
                )}
                {totaux.sansCout > 0 && ['decompte', 'cloture'].includes(inventaire.statut) && (
                    <p className="text-sm text-amber-800">{totaux.sansCout} ligne(s) en écart sans coût unitaire : renseignez le coût sur la fiche de l’article (ou du kit) pour les valoriser.</p>
                )}

                {inventaire.statut !== 'brouillon' && <TableauLignes inventaire={inventaire} lignes={lignes} selection={selection} setSelection={setSelection} />}

                {inconnus.length > 0 && (
                    <Carte titre={`N° hors système (${inconnus.length})`}>
                        <p className="mb-2 text-sm text-slate-600">Ces codes ont été scannés mais ne correspondent à aucune unité connue : à régulariser par une réception.</p>
                        <ul className="flex flex-wrap gap-2 text-sm">
                            {inconnus.map((c, i) => (
                                <li key={i} className="rounded bg-slate-100 px-2 py-0.5 font-mono text-xs">
                                    {c.code_lu} · {c.site} · tour {c.tour}
                                </li>
                            ))}
                        </ul>
                    </Carte>
                )}

                {inventaire.statut === 'decompte' && peut('inventaire.cloturer') && (
                    <Carte titre="Clôture">
                        <label className="mb-3 flex items-start gap-2 text-sm text-slate-700">
                            <input type="checkbox" className="mt-1" checked={ajuster} onChange={(e) => setAjuster(e.target.checked)} />
                            <span>
                                Appliquer les écarts au stock : ajustements des lots, n° de série introuvables déclarés « Perdu », unités perdues retrouvées remises en stock, surplus en nouveaux lots (à étiqueter).
                            </span>
                        </label>
                        <Bouton
                            variante="danger"
                            onClick={() => action(`/inventaires/${inventaire.id}/cloturer`, { ajuster }, `Clôturer l’inventaire${ajuster ? ' et ajuster le stock' : ''} ? Cette action est définitive.`)}
                        >
                            Clôturer l’inventaire
                        </Bouton>
                    </Carte>
                )}
                {inventaire.statut === 'cloture' && (
                    <p className="text-sm text-slate-600">
                        Clôturé le {dateFr(inventaire.cloture_le)} par {inventaire.cloture_par_nom}. {inventaire.stock_ajuste ? 'Le stock a été ajusté.' : 'Le stock n’a pas été ajusté.'}
                    </p>
                )}
            </div>
        </AppLayout>
    );
}
