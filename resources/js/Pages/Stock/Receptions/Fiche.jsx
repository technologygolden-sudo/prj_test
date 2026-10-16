import { Link } from '@inertiajs/react';
import AppLayout from '../../../Layouts/AppLayout';
import PanneauImpression from '../../../Components/PanneauImpression';
import { Bouton, Carte, Pastille, Statut, Tableau, dateFr } from '../../../Components/ui';

export default function Fiche({ reception, unites, imprimantes }) {
    const aImprimer = unites.filter((u) => u.impressions === 0);

    return (
        <AppLayout titre={`Réception n° ${reception.id}`} actions={<Bouton variante="secondaire" href="/receptions">Retour</Bouton>}>
            <div className="space-y-6">
                <Carte>
                    <dl className="grid gap-4 text-sm sm:grid-cols-5">
                        {[
                            ['Date', dateFr(reception.date, false)],
                            ['Bon de livraison', reception.num_bl],
                            ['Fournisseur', reception.fournisseur],
                            ['Pour', reception.espace ?? '—'],
                            ['Réceptionnaire', reception.utilisateur],
                        ].map(([l, v]) => (
                            <div key={l}>
                                <dt className="text-slate-500">{l}</dt>
                                <dd className="font-medium">{v}</dd>
                            </div>
                        ))}
                    </dl>
                </Carte>

                <Carte titre={`Étiquettes à imprimer (${aImprimer.length})`}>
                    {aImprimer.length > 0 ? (
                        <PanneauImpression cibles={aImprimer.map((u) => ({ type: 'unite', id: u.id }))} imprimantes={imprimantes} libelle={`Imprimer ${aImprimer.length} étiquette(s)`} />
                    ) : (
                        <p className="text-sm text-slate-600">Toutes les étiquettes ont été imprimées. Pour une réimpression, ouvrez la fiche de l’unité.</p>
                    )}
                </Carte>

                <Carte titre="Unités reçues">
                    <Tableau colonnes={[{ libelle: 'UID' }, { libelle: 'Article' }, { libelle: 'N° de série / lot' }, { libelle: 'Statut' }, { libelle: 'Étiquette' }]}>
                        {unites.map((u) => (
                            <tr key={u.id} className="hover:bg-slate-50">
                                <td className="px-5 py-2 font-mono text-xs">
                                    <Link href={`/stock/${u.id}`} className="text-green-800 hover:underline">
                                        {u.uid}
                                    </Link>
                                </td>
                                <td className="px-5 py-2">
                                    {u.reference} <span className="text-slate-500">· {u.nom}</span>
                                </td>
                                <td className="px-5 py-2">{u.numero_serie ?? `Lot de ${u.quantite_initiale}`}</td>
                                <td className="px-5 py-2">
                                    <Statut valeur={u.statut} />
                                </td>
                                <td className="px-5 py-2">{u.impressions > 0 ? <Pastille couleur="vert">Imprimée</Pastille> : <Pastille couleur="orange">À imprimer</Pastille>}</td>
                            </tr>
                        ))}
                    </Tableau>
                </Carte>
            </div>
        </AppLayout>
    );
}
