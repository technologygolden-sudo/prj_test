import AppLayout from '../../Layouts/AppLayout';
import { Bouton, Carte, Tableau, dateFr, usePeut } from '../../Components/ui';
import { Optimalite, modes, sources } from './Index';

export default function Resultat({ simulation, kits, articles }) {
    const peut = usePeut();
    const total = (champ) => kits.reduce((s, k) => s + Number(k[champ] ?? 0), 0);
    const manquants = articles.filter((a) => a.manquant > 0);

    return (
        <AppLayout
            titre={`Simulation n° ${simulation.id}`}
            actions={
                <>
                    {peut('simulation.exporter') && (
                        <>
                            <Bouton variante="secondaire" href={`/simulations/${simulation.id}/export/csv`} externe>
                                Excel (CSV)
                            </Bouton>
                            <Bouton variante="secondaire" href={`/simulations/${simulation.id}/export/pdf`} externe>
                                PDF
                            </Bouton>
                            {manquants.length > 0 && (
                                <Bouton variante="secondaire" href={`/simulations/${simulation.id}/export/achats`} externe>
                                    Liste d’achats
                                </Bouton>
                            )}
                        </>
                    )}
                    <Bouton href="/simulations/nouvelle">Nouvelle simulation</Bouton>
                </>
            }
        >
            <div className="space-y-6">
                <div className="grid gap-4 sm:grid-cols-4">
                    {[
                        ['Kits réalisables', `${total('realisable')} / ${total('voulu')}`],
                        ['Mode', modes[simulation.mode]],
                        ['Stock', sources[simulation.source_stock]],
                        ['Articles manquants', manquants.length],
                    ].map(([l, v]) => (
                        <div key={l} className="rounded-lg bg-white p-4 shadow-sm ring-1 ring-slate-200">
                            <p className="text-sm text-slate-600">{l}</p>
                            <p className="mt-1 text-xl font-semibold tabular-nums">{v}</p>
                        </div>
                    ))}
                </div>
                <p className="flex flex-wrap items-center gap-2 text-sm text-slate-600">
                    <Optimalite valeur={simulation.optimalite} /> Calculé en {simulation.duree_ms} ms le {dateFr(simulation.horodatage)} par {simulation.utilisateur}. Le stock réel n’a pas été modifié.
                </p>

                <Carte titre="Kits">
                    <Tableau colonnes={[{ libelle: 'Type de kit' }, { libelle: 'Rang', droite: true }, { libelle: 'Voulu', droite: true }, { libelle: 'Réalisable', droite: true }, { libelle: 'Capacité seul', droite: true }, { libelle: 'Article limitant' }]}>
                        {kits.map((k) => (
                            <tr key={k.libelle}>
                                <td className="px-5 py-2">{k.libelle}</td>
                                <td className="px-5 py-2 text-right tabular-nums">{k.rang}</td>
                                <td className="px-5 py-2 text-right tabular-nums">{k.voulu}</td>
                                <td className={`px-5 py-2 text-right font-medium tabular-nums ${k.realisable < k.voulu ? 'text-amber-800' : 'text-green-800'}`}>{k.realisable ?? '—'}</td>
                                <td className="px-5 py-2 text-right tabular-nums">{k.capacite}</td>
                                <td className="px-5 py-2">{k.limitant ?? '—'}</td>
                            </tr>
                        ))}
                    </Tableau>
                </Carte>

                <Carte titre="Articles">
                    <Tableau colonnes={[{ libelle: 'Article' }, { libelle: 'Disponible', droite: true }, { libelle: 'Besoin', droite: true }, { libelle: 'Consommé', droite: true }, { libelle: 'Manquant', droite: true }, { libelle: 'Reliquat', droite: true }]}>
                        {articles.map((a) => (
                            <tr key={a.reference}>
                                <td className="px-5 py-2">
                                    <span className="font-medium">{a.reference}</span> <span className="text-slate-500">{a.nom}</span>
                                </td>
                                <td className="px-5 py-2 text-right tabular-nums">{a.disponible}</td>
                                <td className="px-5 py-2 text-right tabular-nums">{a.besoin}</td>
                                <td className="px-5 py-2 text-right tabular-nums">{a.consomme}</td>
                                <td className={`px-5 py-2 text-right tabular-nums ${a.manquant > 0 ? 'font-medium text-amber-800' : ''}`}>{a.manquant}</td>
                                <td className="px-5 py-2 text-right tabular-nums">{a.reliquat}</td>
                            </tr>
                        ))}
                    </Tableau>
                </Carte>
            </div>
        </AppLayout>
    );
}
