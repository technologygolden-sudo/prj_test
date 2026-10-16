import AppLayout from '../../../Layouts/AppLayout';
import { Bouton, Carte, Pagination, Tableau, dateFr } from '../../../Components/ui';

const types = { expedition: 'Remise technicien', rebut: 'Mise au rebut', retour_fournisseur: 'Retour fournisseur' };

export default function Index({ sorties }) {
    return (
        <AppLayout titre="Sorties de stock" actions={<Bouton href="/sorties/nouvelle">Nouvelle sortie</Bouton>}>
            <Carte>
                <Tableau
                    colonnes={[{ libelle: 'N°' }, { libelle: 'Date' }, { libelle: 'Type' }, { libelle: 'Technicien / destinataire' }, { libelle: 'Commande CRM / document' }, { libelle: 'Lignes', droite: true }, { libelle: 'Par' }]}
                    vide={sorties.data.length === 0 ? 'Aucune sortie pour l’instant.' : null}
                >
                    {sorties.data.map((s) => (
                        <tr key={s.id}>
                            <td className="px-5 py-2 font-medium">{s.id}</td>
                            <td className="px-5 py-2">{dateFr(s.date, false)}</td>
                            <td className="px-5 py-2">{types[s.type] ?? s.type}</td>
                            <td className="px-5 py-2">{s.destinataire ?? '—'}</td>
                            <td className="px-5 py-2">{s.document ?? '—'}</td>
                            <td className="px-5 py-2 text-right tabular-nums">{s.lignes}</td>
                            <td className="px-5 py-2 text-slate-600">{s.utilisateur}</td>
                        </tr>
                    ))}
                </Tableau>
                <Pagination liens={sorties.links} />
            </Carte>
        </AppLayout>
    );
}
