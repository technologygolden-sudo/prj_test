import { router } from '@inertiajs/react';
import AppLayout from '../../../Layouts/AppLayout';
import { Bouton, Carte, Pagination, Pastille, Tableau, dateFr, usePeut } from '../../../Components/ui';

export const statutsInventaire = {
    brouillon: ['Brouillon', 'gris'],
    comptage: ['Comptage en cours', 'orange'],
    analyse: ['Analyse des écarts', 'orange'],
    decompte: ['Décompte final', 'orange'],
    cloture: ['Clôturé', 'vert'],
    annule: ['Annulé', 'rouge'],
};

export function StatutInventaire({ inventaire }) {
    const [libelle, couleur] = statutsInventaire[inventaire.statut] ?? [inventaire.statut, 'gris'];
    const tour = inventaire.statut === 'comptage' ? ` (${inventaire.tour === 1 ? '1er' : '2e'})` : '';
    return <Pastille couleur={couleur}>{libelle + tour}</Pastille>;
}

export const fcfa = (v) => (v === null || v === undefined ? '—' : `${Number(v).toLocaleString('fr-FR', { maximumFractionDigits: 0 })} FCFA`);

export default function Index({ inventaires }) {
    const peut = usePeut();
    return (
        <AppLayout titre="Inventaires" actions={peut('inventaire.gerer') && <Bouton href="/inventaires/nouveau">Nouvelle campagne</Bouton>}>
            <Carte>
                <Tableau
                    colonnes={[{ libelle: 'N°' }, { libelle: 'Campagne' }, { libelle: 'Date' }, { libelle: 'Sites' }, { libelle: 'Statut' }, { libelle: 'Écart valorisé', droite: true }]}
                    vide={inventaires.data.length === 0 ? 'Aucune campagne d’inventaire.' : null}
                >
                    {inventaires.data.map((i) => (
                        <tr key={i.id} className="cursor-pointer hover:bg-slate-50" onClick={() => router.visit(`/inventaires/${i.id}`)}>
                            <td className="px-5 py-2 font-medium text-green-800">{i.id}</td>
                            <td className="px-5 py-2">{i.libelle}</td>
                            <td className="px-5 py-2 whitespace-nowrap">{dateFr(i.date_prevue, false)}</td>
                            <td className="max-w-64 truncate px-5 py-2 text-slate-600" title={i.sites}>
                                {i.sites ?? '—'}
                            </td>
                            <td className="px-5 py-2">
                                <StatutInventaire inventaire={i} />
                            </td>
                            <td className={`px-5 py-2 text-right tabular-nums ${Number(i.valeur_ecart) < 0 ? 'text-red-700' : ''}`}>{fcfa(i.valeur_ecart)}</td>
                        </tr>
                    ))}
                </Tableau>
                <Pagination liens={inventaires.links} />
            </Carte>
        </AppLayout>
    );
}
