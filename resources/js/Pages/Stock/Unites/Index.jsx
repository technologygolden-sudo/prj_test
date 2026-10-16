import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../Layouts/AppLayout';
import { Bouton, Carte, Liste, Pagination, Saisie, Statut, Tableau } from '../../../Components/ui';

const statuts = [
    ['en_stock', 'En stock'],
    ['assemble', 'Assemblé'],
    ['epuise', 'Épuisé'],
    ['expedie', 'Expédié'],
    ['rebute', 'Rebuté'],
    ['retourne', 'Retourné'],
    ['perdu', 'Perdu'],
];

export default function Index({ unites, filtres, synthese }) {
    const [q, setQ] = useState(filtres.q ?? '');
    const filtrer = (changements) => router.get('/stock', { ...filtres, q: q || undefined, ...changements }, { preserveState: true, replace: true });

    return (
        <AppLayout titre="Stock">
            <div className="grid gap-6 lg:grid-cols-3">
                <Carte titre="Disponible par article" className="lg:order-2">
                    {synthese.length === 0 ? (
                        <p className="text-sm text-slate-500">Aucune unité en stock.</p>
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
                <Carte className="lg:col-span-2">
                    <form
                        className="mb-4 flex flex-wrap gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            filtrer({ page: undefined });
                        }}
                    >
                        <Saisie type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="UID, n° de série, article" className="max-w-xs" aria-label="Rechercher" />
                        <Liste value={filtres.statut ?? ''} onChange={(e) => filtrer({ statut: e.target.value || undefined, page: undefined })} className="max-w-44" aria-label="Statut">
                            <option value="">Tous statuts</option>
                            {statuts.map(([v, l]) => (
                                <option key={v} value={v}>
                                    {l}
                                </option>
                            ))}
                        </Liste>
                        <Bouton type="submit" variante="secondaire">
                            Rechercher
                        </Bouton>
                    </form>
                    <Tableau
                        colonnes={[{ libelle: 'Article' }, { libelle: 'N° de série / lot' }, { libelle: 'Reste', droite: true }, { libelle: 'Statut' }, { libelle: 'Emplacement' }]}
                        vide={unites.data.length === 0 ? 'Aucune unité ne correspond.' : null}
                    >
                        {unites.data.map((u) => (
                            <tr key={u.id} className="hover:bg-slate-50">
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
                                <td className="px-5 py-2">
                                    <Statut valeur={u.statut} />
                                </td>
                                <td className="px-5 py-2 text-slate-600">{u.emplacement ?? '—'}</td>
                            </tr>
                        ))}
                    </Tableau>
                    <Pagination liens={unites.links} />
                </Carte>
            </div>
        </AppLayout>
    );
}
