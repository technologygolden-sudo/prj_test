import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../Layouts/AppLayout';
import PanneauImpression from '../../../Components/PanneauImpression';
import { EtatPastille } from '../../../Components/ActionsElement';
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

function Filtres({ filtres, sites, emplacements, vue }) {
    const [q, setQ] = useState(filtres.q ?? '');
    const filtrer = (changements) =>
        router.get('/stock', { ...filtres, q: q || undefined, vue: vue === 'sites' ? 'sites' : undefined, ...changements, page: undefined }, { preserveState: true, replace: true });

    return (
        <form
            className="mb-4 flex flex-wrap gap-3"
            onSubmit={(e) => {
                e.preventDefault();
                filtrer({});
            }}
        >
            {vue === 'liste' && <Saisie type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="UID, n° de série, article" className="max-w-xs" aria-label="Rechercher" />}
            <Liste value={filtres.site ?? ''} onChange={(e) => filtrer({ site: e.target.value || undefined, emplacement: undefined })} className="max-w-56" aria-label="Site">
                <option value="">Tous les sites</option>
                {sites.map((s) => (
                    <option key={s.id} value={s.id}>
                        {s.nom}
                    </option>
                ))}
            </Liste>
            {vue === 'liste' && (
                <Liste value={filtres.emplacement ?? ''} onChange={(e) => filtrer({ emplacement: e.target.value || undefined })} className="max-w-64" aria-label="Emplacement">
                    <option value="">Tous les emplacements</option>
                    {emplacements.map((e) => (
                        <option key={e.id} value={e.id}>
                            {filtres.site ? e.nom : e.libelle}
                        </option>
                    ))}
                </Liste>
            )}
            <Liste value={filtres.proprietaire ?? ''} onChange={(e) => filtrer({ proprietaire: e.target.value || undefined })} className="max-w-40" aria-label="Propriétaire">
                <option value="">GREENO et TEVIA</option>
                <option value="GREENO">GREENO</option>
                <option value="TEVIA">TEVIA</option>
            </Liste>
            <Liste value={filtres.etat ?? ''} onChange={(e) => filtrer({ etat: e.target.value || undefined })} className="max-w-40" aria-label="État">
                <option value="">Tous états</option>
                <option value="bon">Bon état</option>
                <option value="defectueux">Défectueux</option>
            </Liste>
            {vue === 'liste' && (
                <Liste value={filtres.statut ?? ''} onChange={(e) => filtrer({ statut: e.target.value || undefined })} className="max-w-44" aria-label="Statut">
                    <option value="">Tous statuts</option>
                    {statuts.map(([v, l]) => (
                        <option key={v} value={v}>
                            {l}
                        </option>
                    ))}
                </Liste>
            )}
            {vue === 'liste' && (
                <Bouton type="submit" variante="secondaire">
                    Rechercher
                </Bouton>
            )}
        </form>
    );
}

function VueListe({ unites, synthese, imprimantes }) {
    const [selection, setSelection] = useState([]);
    const tous = unites.data.map((u) => u.id);
    const basculer = (id) => setSelection(selection.includes(id) ? selection.filter((x) => x !== id) : [...selection, id]);
    const choisies = unites.data.filter((u) => selection.includes(u.id));

    return (
        <div className="grid gap-6 lg:grid-cols-3">
            <Carte titre="Disponible par article" className="lg:order-2">
                {synthese.length === 0 ? (
                    <p className="text-sm text-slate-500">Aucune unité en stock pour ces filtres.</p>
                ) : (
                    <ul className="divide-y divide-slate-100 text-sm">
                        {synthese.map((s) => (
                            <li key={s.reference} className="flex justify-between gap-3 py-1.5">
                                <span>
                                    <span className="font-medium">{s.reference}</span> <span className="text-slate-500">{s.nom}</span>
                                </span>
                                <span className="font-medium tabular-nums">{s.quantite}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </Carte>
            <div className="space-y-4 lg:col-span-2">
                {choisies.length > 0 && (
                    <Carte titre={`Étiquettes de ${choisies.length} élément(s) sélectionné(s)`}>
                        <PanneauImpression
                            cibles={choisies.map((u) => ({ type: 'unite', id: u.id }))}
                            imprimantes={imprimantes}
                            dejaImprime={choisies.some((u) => u.impressions > 0)}
                            libelle={`Imprimer ${choisies.length} étiquette(s)`}
                        />
                    </Carte>
                )}
                <Carte>
                    <Tableau
                        colonnes={[{ libelle: '' }, { libelle: 'Article' }, { libelle: 'N° de série / lot' }, { libelle: 'Reste', droite: true }, { libelle: 'Emplacement' }, { libelle: 'Statut' }]}
                        vide={unites.data.length === 0 ? 'Aucune unité ne correspond.' : null}
                    >
                        <tr className="bg-slate-50">
                            <td className="px-5 py-1.5" colSpan={6}>
                                <label className="flex items-center gap-2 text-xs text-slate-600">
                                    <input type="checkbox" checked={selection.length > 0 && selection.length === tous.length} onChange={(e) => setSelection(e.target.checked ? tous : [])} />
                                    Tout sélectionner sur cette page (pour imprimer les étiquettes)
                                </label>
                            </td>
                        </tr>
                        {unites.data.map((u) => (
                            <tr key={u.id} className={classes('hover:bg-slate-50', selection.includes(u.id) && 'bg-green-50/60')}>
                                <td className="px-5 py-2">
                                    <input type="checkbox" checked={selection.includes(u.id)} onChange={() => basculer(u.id)} aria-label={`Sélectionner ${u.uid}`} />
                                </td>
                                <td className="px-5 py-2">
                                    <Link href={`/stock/${u.id}`} className="font-medium text-green-800 hover:underline">
                                        {u.reference}
                                    </Link>{' '}
                                    <span className="text-slate-500">{u.nom}</span>
                                </td>
                                <td className="px-5 py-2">{u.numero_serie ?? <span className="text-slate-600">Lot</span>}</td>
                                <td className="px-5 py-2 text-right tabular-nums">
                                    {u.quantite_restante}/{u.quantite_initiale}
                                </td>
                                <td className="px-5 py-2 text-slate-600">
                                    {u.emplacement_libelle || '—'} <Pastille>{u.proprietaire}</Pastille>
                                </td>
                                <td className="px-5 py-2">
                                    <div className="flex flex-wrap gap-1">
                                        <Statut valeur={u.statut} />
                                        {u.etat === 'defectueux' && <EtatPastille etat="defectueux" />}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </Tableau>
                    <Pagination liens={unites.links} />
                </Carte>
            </div>
        </div>
    );
}

function VueSites({ parSite }) {
    if (parSite.length === 0) {
        return <Carte>Aucun site actif.</Carte>;
    }
    return (
        <div className="space-y-6">
            {parSite.map((site) => (
                <Carte
                    key={site.site_id}
                    titre={site.site}
                    actions={
                        <span className="text-sm text-slate-600">
                            {site.total_articles} article(s) · {site.total_kits} kit(s)
                        </span>
                    }
                >
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
    const changerVue = (v) => router.get('/stock', { ...filtres, vue: v === 'sites' ? 'sites' : undefined, page: undefined }, { preserveState: false });

    return (
        <AppLayout
            titre="Stock"
            actions={
                <div className="inline-flex rounded-md ring-1 ring-slate-300" role="tablist" aria-label="Affichage du stock">
                    {[
                        ['liste', 'Liste détaillée'],
                        ['sites', 'Par site'],
                    ].map(([v, l]) => (
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
            {vue === 'sites' ? <VueSites parSite={parSite} /> : <VueListe unites={unites} synthese={synthese} imprimantes={imprimantes} />}
        </AppLayout>
    );
}
