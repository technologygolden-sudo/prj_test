import { Pastille, Tableau, dateFr } from './ui';

const typesMouvement = {
    entree: 'Entrée',
    consommation: 'Consommation',
    restitution: 'Restitution',
    sortie: 'Sortie',
    ajustement: 'Ajustement',
};

export function Mouvements({ mouvements }) {
    return (
        <Tableau colonnes={[{ libelle: 'Date' }, { libelle: 'Mouvement' }, { libelle: 'Quantité', droite: true }, { libelle: 'Motif' }, { libelle: 'Par' }]} vide={mouvements.length === 0 ? 'Aucun mouvement.' : null}>
            {mouvements.map((m) => (
                <tr key={m.id}>
                    <td className="px-5 py-2 whitespace-nowrap">{dateFr(m.horodatage)}</td>
                    <td className="px-5 py-2">{typesMouvement[m.type] ?? m.type}</td>
                    <td className={`px-5 py-2 text-right tabular-nums ${m.quantite < 0 ? 'text-red-700' : 'text-green-800'}`}>
                        {m.quantite > 0 ? `+${m.quantite}` : m.quantite}
                    </td>
                    <td className="px-5 py-2 text-slate-600">{m.motif ?? '—'}</td>
                    <td className="px-5 py-2 text-slate-600">{m.utilisateur}</td>
                </tr>
            ))}
        </Tableau>
    );
}

export function Impressions({ impressions }) {
    return (
        <Tableau colonnes={[{ libelle: 'Date' }, { libelle: 'Imprimante' }, { libelle: 'Copies', droite: true }, { libelle: 'Type' }, { libelle: 'Par' }]} vide={impressions.length === 0 ? 'Jamais imprimée.' : null}>
            {impressions.map((i, n) => (
                <tr key={n}>
                    <td className="px-5 py-2 whitespace-nowrap">{dateFr(i.horodatage)}</td>
                    <td className="px-5 py-2">{i.imprimante}</td>
                    <td className="px-5 py-2 text-right tabular-nums">{i.copies}</td>
                    <td className="px-5 py-2">{i.est_reimpression ? <span><Pastille couleur="orange">Réimpression</Pastille> <span className="text-slate-600">{i.motif}</span></span> : <Pastille>Première</Pastille>}</td>
                    <td className="px-5 py-2 text-slate-600">{i.utilisateur}</td>
                </tr>
            ))}
        </Tableau>
    );
}
