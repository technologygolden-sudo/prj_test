import AppLayout from '../../Layouts/AppLayout';
import { Bouton, Carte, Tableau, usePeut } from '../../Components/ui';

export default function Capacite({ lignes }) {
    const peut = usePeut();
    return (
        <AppLayout titre="Capacité de production" actions={peut('simulation.lancer') && <Bouton href="/simulations/nouvelle">Simuler plusieurs kits</Bouton>}>
            <Carte>
                <p className="mb-4 text-sm text-slate-600">Nombre maximal de kits réalisables pour chaque type pris seul, sur le stock réel actuel.</p>
                <Tableau
                    colonnes={[{ libelle: 'Type de kit' }, { libelle: 'Version', droite: true }, { libelle: 'Capacité', droite: true }, { libelle: 'Article limitant' }, { libelle: 'Stock du limitant', droite: true }]}
                    vide={lignes.length === 0 ? 'Aucun type de kit n’a encore de nomenclature.' : null}
                >
                    {lignes.map((l) => (
                        <tr key={l.reference}>
                            <td className="px-5 py-2">
                                <span className="font-medium">{l.reference}</span> <span className="text-slate-500">{l.nom}</span>
                            </td>
                            <td className="px-5 py-2 text-right">
                                v{l.version}
                                {l.brouillon && <span className="ml-1 text-xs text-amber-700">brouillon</span>}
                            </td>
                            <td className="px-5 py-2 text-right text-lg font-semibold tabular-nums">{l.capacite}</td>
                            <td className="px-5 py-2">{l.limitant ?? '—'}</td>
                            <td className="px-5 py-2 text-right tabular-nums">{l.stock_limitant ?? '—'}</td>
                        </tr>
                    ))}
                </Tableau>
            </Carte>
        </AppLayout>
    );
}
