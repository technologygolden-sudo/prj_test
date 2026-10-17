import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../Layouts/AppLayout';
import PanneauImpression from '../../../Components/PanneauImpression';
import { Bouton, Carte, Liste, Pagination, Pastille, Saisie, Statut, Tableau, classes } from '../../../Components/ui';

const statuts = [
    ['en_stock', 'En stock'],
    ['en_transit', 'En transit'],
    ['assemble', 'Assemblé'],
    ['epuise', 'Épuisé'],
    ['expedie', 'Remis au technicien'],
    ['rebute', 'Rebuté'],
    ['retourne', 'Retourné'],
    ['perdu', 'Perdu'],
];

const vues = [
    ['liste', 'Liste'],
    ['articles', 'Par article'],
    ['sites', 'Par site'],
];

/** Une seule ligne : recherche + site ; les autres filtres derrière « Filtres ». */
function Filtres({ filtres, sites, emplacements, vue }) {
    const [q, setQ] = useState(filtres.q ?? '');
    const avances = ['emplacement', 'proprietaire', 'etat', 'statut'].filter((c) => filtres[c]).length;
    const [ouvert, setOuvert] = useState(avances > 0);
    const filtrer = (changements) =>
        router.get('/stock', { ...filtres, q: q || undefined, vue: vue === 'liste' ? undefined : vue, ...changements, page: undefined }, { preserveState: true, replace: true });

    return (
        <div className="mb-4">
            <form
                className="flex flex-wrap items-center gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    filtrer({});
                }}
            >
                <Saisie type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Rechercher : n° de série, UID, article…" className="max-w-sm flex-1" aria-label="Rechercher" />
                <Liste value={filtres.site ?? ''} onChange={(e) => filtrer({ site: e.target.value || undefined, emplacement: undefined })} className="max-w-60" aria-label="Site">
                    <option value="">Tous les sites</option>
                    {sites.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.nom}
                        </option>
                    ))}
                </Liste>
                <Bouton variante="secondaire" onClick={() => setOuvert(!ouvert)} aria-expanded={ouvert}>
                    Filtres{avances > 0 ? ` (${avances})` : ''}
                </Bouton>
                {avances > 0 && (
                    <Bouton variante="discret" onClick={() => filtrer({ emplacement: undefined, proprietaire: undefined, etat: undefined, statut: undefined })}>
                        Effacer
                    </Bouton>
                )}
            </form>
            {ouvert && (
                <div className="mt-2 flex flex-wrap gap-2 rounded-md bg-slate-50 p-2 ring-1 ring-slate-200">
                    <Liste value={filtres.emplacement ?? ''} onChange={(e) => filtrer({ emplacement: e.target.value || undefined })} className="max-w-64" aria-label="Emplacement">
                        <option value="">Tous les emplacements</option>
                        {emplacements.map((e) => (
                            <option key={e.id} value={e.id}>
                                {filtres.site ? e.nom : e.libelle}
                            </option>
                        ))}
                    </Liste>
                    <Liste value={filtres.proprietaire ?? ''} onChange={(e) => filtrer({ proprietaire: e.target.value || undefined })} className="max-w-44" aria-label="Propriétaire">
                        <option value="">GREENO et TEVIA</option>
                        <option value="GREENO">GREENO</option>
                        <option value="TEVIA">TEVIA</option>
                    </Liste>
                    <Liste value={filtres.etat ?? ''} onChange={(e) => filtrer({ etat: e.target.value || undefined })} className="max-w-40" aria-label="État">
                        <option value="">Tous états</option>
                        <option value="bon">Bon état</option>
                        <option value="defectueux">Défectueux</option>
                    </Liste>
                    <Liste value={filtres.statut ?? ''} onChange={(e) => filtrer({ statut: e.target.value || undefined })} className="max-w-48" aria-label="Statut">
                        <option value="">Tous statuts</option>
                        {statuts.map(([v, l]) => (
                            <option key={v} value={v}>
                                {l}
                            </option>
                        ))}
                    </Liste>
                </div>
            )}
        </div>
    );
}

function VueListe({ unites, imprimantes, unSite }) {
    const [selection, setSelection] = useState([]);
    const tous = unites.data.map((u) => u.id);
    const basculer = (id) => setSelection(selection.includes(id) ? selection.filter((x) => x !== id) : [...selection, id]);
    const choisies = unites.data.filter((u) => selection.includes(u.id));

    return (
        <div className="space-y-4">
            {choisies.length > 0 && (
                <Carte titre={`Étiquettes : ${choisies.length} élément(s) sélectionné(s)`} actions={<Bouton variante="discret" onClick={() => setSelection([])}>Désélectionner</Bouton>}>
                    <PanneauImpression
                        cibles={choisies.map((u) => ({ type: 'unite', id: u.id }))}
                        imprimantes={imprimantes}
                        dejaImprime={choisies.some((u) => u.impressions > 0)}
                        libelle={`Imprimer ${choisies.length} étiquette(s)`}
                    />
                </Carte>
            )}
            <section className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                            <tr>
                                <th className="w-10 px-4 py-2">
                                    <input
                                        type="checkbox"
                                        checked={tous.length > 0 && selection.length === tous.length}
                                        onChange={(e) => setSelection(e.target.checked ? tous : [])}
                                        aria-label="Tout sélectionner sur cette page"
                                        title="Tout sélectionner (impression d’étiquettes)"
                                    />
                                </th>
                                <th className="px-3 py-2 font-medium">Article</th>
                                <th className="px-3 py-2 font-medium">N° de série / lot</th>
                                <th className="px-3 py-2 text-right font-medium">Qté</th>
                                <th className="px-3 py-2 font-medium">Emplacement</th>
                                <th className="px-3 py-2 font-medium">Statut</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {unites.data.map((u) => (
                                <tr key={u.id} className={classes('whitespace-nowrap hover:bg-slate-50', selection.includes(u.id) && 'bg-green-50/60')}>
                                    <td className="px-4 py-1.5">
                                        <input type="checkbox" checked={selection.includes(u.id)} onChange={() => basculer(u.id)} aria-label={`Sélectionner ${u.uid}`} />
                                    </td>
                                    <td className="max-w-72 truncate px-3 py-1.5" title={u.nom}>
                                        <Link href={`/stock/${u.id}`} className="font-medium text-green-800 hover:underline">
                                            {u.reference}
                                        </Link>{' '}
                                        <span className="text-slate-500">{u.nom}</span>
                                    </td>
                                    <td className="px-3 py-1.5 font-mono text-xs">{u.numero_serie ?? <span className="font-sans text-slate-500">Lot</span>}</td>
                                    <td className="px-3 py-1.5 text-right tabular-nums">{u.nature === 'lot' ? `${u.quantite_restante}/${u.quantite_initiale}` : 1}</td>
                                    <td className="max-w-80 truncate px-3 py-1.5 text-slate-600" title={u.emplacement_libelle}>
                                        {unSite ? (u.emplacement_libelle ?? '').split(' › ').pop() || '—' : u.emplacement_libelle || '—'}
                                        <span className="ml-1.5 text-xs text-slate-400">{u.proprietaire}</span>
                                    </td>
                                    <td className="px-3 py-1.5">
                                        <span className="inline-flex gap-1">
                                            <Statut valeur={u.statut} />
                                            {u.etat === 'defectueux' && <Pastille couleur="rouge">Défectueux</Pastille>}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {unites.data.length === 0 && <p className="px-5 py-8 text-center text-sm text-slate-500">Aucune unité ne correspond.</p>}
                </div>
                <div className="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-2 text-xs text-slate-500">
                    <span>{unites.total} unité(s)</span>
                    <Pagination liens={unites.links} />
                </div>
            </section>
        </div>
    );
}

function VueArticles({ synthese }) {
    const total = synthese.reduce((s, a) => s + Number(a.quantite), 0);
    return (
        <Carte actions={<span className="text-sm text-slate-600">{total} unité(s) en stock</span>}>
            <Tableau colonnes={[{ libelle: 'Article' }, { libelle: 'Désignation' }, { libelle: 'Disponible', droite: true }]} vide={synthese.length === 0 ? 'Aucune unité en stock pour ces filtres.' : null}>
                {synthese.map((a) => (
                    <tr key={a.reference} className="hover:bg-slate-50">
                        <td className="px-5 py-1.5 font-medium">
                            <Link href={`/stock?q=${encodeURIComponent(a.reference)}`} className="text-green-800 hover:underline">
                                {a.reference}
                            </Link>
                        </td>
                        <td className="px-5 py-1.5 text-slate-600">{a.nom}</td>
                        <td className="px-5 py-1.5 text-right font-medium tabular-nums">{a.quantite}</td>
                    </tr>
                ))}
            </Tableau>
        </Carte>
    );
}

function VueSites({ parSite }) {
    if (!parSite?.length) {
        return <Carte>Aucun site actif.</Carte>;
    }
    return (
        <div className="space-y-6">
            {parSite.map((site) => (
                <Carte key={site.site_id} titre={site.site} actions={<span className="text-sm text-slate-600">{site.total_articles} article(s) · {site.total_kits} kit(s)</span>}>
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {site.emplacements.map((e) => {
                            const vide = e.articles.length === 0 && e.kits.length === 0;
                            return (
                                <div key={e.id} className={classes('rounded-md p-3 ring-1', vide ? 'ring-slate-100' : 'ring-slate-200')}>
                                    <div className="mb-2 flex items-center justify-between gap-2">
                                        <Link href={`/stock?emplacement=${e.id}`} className="font-medium text-slate-900 hover:text-green-800">
                                            {e.nom}
                                        </Link>
                                        <div className="flex gap-1">
                                            {e.vente && <Pastille couleur="vert">Shop</Pastille>}
                                            {e.espace && <Pastille>{e.espace}</Pastille>}
                                        </div>
                                    </div>
                                    {vide ? (
                                        <p className="text-xs text-slate-400">Vide</p>
                                    ) : (
                                        <ul className="space-y-0.5 text-sm">
                                            {e.kits.map((k) => (
                                                <li key={`k${k.reference}${k.proprietaire}`} className="flex justify-between gap-2">
                                                    <span className="truncate">
                                                        <span className="font-medium">Kit {k.reference}</span>
                                                        {!e.espace && <span className="text-xs text-slate-500"> · {k.proprietaire}</span>}
                                                    </span>
                                                    <span className="shrink-0 tabular-nums">
                                                        {k.quantite}
                                                        {k.defectueux > 0 && <span className="text-red-700"> ({k.defectueux} déf.)</span>}
                                                    </span>
                                                </li>
                                            ))}
                                            {e.articles.map((a) => (
                                                <li key={`a${a.reference}${a.proprietaire}`} className="flex justify-between gap-2">
                                                    <span className="truncate" title={a.nom}>
                                                        {a.reference} <span className="text-slate-500">{a.nom}</span>
                                                        {!e.espace && <span className="text-xs text-slate-500"> · {a.proprietaire}</span>}
                                                    </span>
                                                    <span className="shrink-0 tabular-nums">
                                                        {a.quantite}
                                                        {a.defectueux > 0 && <span className="text-red-700"> ({a.defectueux} déf.)</span>}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </Carte>
            ))}
        </div>
    );
}

export default function Index({ unites, filtres, synthese, sites, emplacements, imprimantes, parSite, vue }) {
    const changerVue = (v) => router.get('/stock', { ...filtres, vue: v === 'liste' ? undefined : v, page: undefined }, { preserveState: false });

    return (
        <AppLayout
            titre="Stock"
            actions={
                <div className="inline-flex rounded-md ring-1 ring-slate-300" role="tablist" aria-label="Affichage du stock">
                    {vues.map(([v, l]) => (
                        <button
                            key={v}
                            type="button"
                            role="tab"
                            aria-selected={vue === v}
                            onClick={() => changerVue(v)}
                            className={classes('px-3 py-1.5 text-sm font-medium first:rounded-l-md last:rounded-r-md', vue === v ? 'bg-green-800 text-white' : 'bg-white text-slate-700 hover:bg-slate-50')}
                        >
                            {l}
                        </button>
                    ))}
                </div>
            }
        >
            <Filtres filtres={filtres} sites={sites} emplacements={emplacements} vue={vue} />
            {vue === 'sites' && <VueSites parSite={parSite} />}
            {vue === 'articles' && <VueArticles synthese={synthese} />}
            {vue === 'liste' && <VueListe unites={unites} imprimantes={imprimantes} unSite={Boolean(filtres.site) || sites.length === 1} />}
        </AppLayout>
    );
}
