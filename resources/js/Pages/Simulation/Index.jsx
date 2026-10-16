import { Link } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { Bouton, Carte, Pagination, Pastille, Tableau, dateFr } from '../../Components/ui';

export const modes = { priorite: 'Priorité', equilibre: 'Équilibré', volume: 'Volume' };
export const sources = { saisie: 'Saisie', import: 'Import', reel: 'Stock réel' };

export function Optimalite({ valeur }) {
    if (valeur === 'optimal') return <Pastille couleur="vert">Optimal</Pastille>;
    if (valeur === 'realisable') return <Pastille couleur="orange">Réalisable, non prouvé optimal</Pastille>;
    return <Pastille couleur="rouge">Non résolu</Pastille>;
}

export default function Index({ simulations }) {
    return (
        <AppLayout titre="Simulations" actions={<Bouton href="/simulations/nouvelle">Nouvelle simulation</Bouton>}>
            <Carte>
                <Tableau
                    colonnes={[{ libelle: 'N°' }, { libelle: 'Date' }, { libelle: 'Mode' }, { libelle: 'Stock' }, { libelle: 'Kits voulus', droite: true }, { libelle: 'Réalisables', droite: true }, { libelle: 'Résultat' }, { libelle: 'Par' }]}
                    vide={simulations.data.length === 0 ? 'Aucune simulation enregistrée.' : null}
                >
                    {simulations.data.map((s) => (
                        <tr key={s.id} className="hover:bg-slate-50">
                            <td className="px-5 py-2 font-medium">
                                <Link href={`/simulations/${s.id}`} className="text-green-800 hover:underline">
                                    {s.id}
                                </Link>
                            </td>
                            <td className="px-5 py-2">{dateFr(s.horodatage)}</td>
                            <td className="px-5 py-2">{modes[s.mode]}</td>
                            <td className="px-5 py-2">{sources[s.source_stock]}</td>
                            <td className="px-5 py-2 text-right tabular-nums">{s.voulus}</td>
                            <td className="px-5 py-2 text-right tabular-nums">{s.realisables ?? '—'}</td>
                            <td className="px-5 py-2">
                                <Optimalite valeur={s.optimalite} />
                            </td>
                            <td className="px-5 py-2 text-slate-600">{s.utilisateur}</td>
                        </tr>
                    ))}
                </Tableau>
                <Pagination liens={simulations.links} />
            </Carte>
        </AppLayout>
    );
}
