import { Link } from '@inertiajs/react';
import AppLayout from '../../../Layouts/AppLayout';
import { Bouton, Carte, Pagination, Tableau, dateFr } from '../../../Components/ui';

export default function Index({ receptions }) {
    return (
        <AppLayout titre="Réceptions" actions={<Bouton href="/receptions/nouvelle">Nouvelle réception</Bouton>}>
            <Carte>
                <Tableau
                    colonnes={[{ libelle: 'N°' }, { libelle: 'Date' }, { libelle: 'Bon de livraison' }, { libelle: 'Fournisseur' }, { libelle: 'Unités', droite: true }, { libelle: 'Par' }]}
                    vide={receptions.data.length === 0 ? 'Aucune réception pour l’instant.' : null}
                >
                    {receptions.data.map((r) => (
                        <tr key={r.id} className="hover:bg-slate-50">
                            <td className="px-5 py-2 font-medium">
                                <Link href={`/receptions/${r.id}`} className="text-green-800 hover:underline">
                                    {r.id}
                                </Link>
                            </td>
                            <td className="px-5 py-2">{dateFr(r.date, false)}</td>
                            <td className="px-5 py-2">{r.num_bl}</td>
                            <td className="px-5 py-2">{r.fournisseur}</td>
                            <td className="px-5 py-2 text-right tabular-nums">{r.unites}</td>
                            <td className="px-5 py-2 text-slate-600">{r.utilisateur}</td>
                        </tr>
                    ))}
                </Tableau>
                <Pagination liens={receptions.links} />
            </Carte>
        </AppLayout>
    );
}
