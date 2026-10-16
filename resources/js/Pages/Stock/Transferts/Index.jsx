import { Link, router } from '@inertiajs/react';
import AppLayout from '../../../Layouts/AppLayout';
import { Bouton, Carte, Liste, Pagination, Pastille, Tableau, dateFr, usePeut } from '../../../Components/ui';

export const statutsTransfert = {
    en_transit: ['En transit', 'orange'],
    recu_partiel: ['Reçu en partie', 'orange'],
    recu: ['Reçu', 'vert'],
};

export function StatutTransfert({ valeur }) {
    const [libelle, couleur] = statutsTransfert[valeur] ?? [valeur, 'gris'];
    return <Pastille couleur={couleur}>{libelle}</Pastille>;
}

export default function Index({ transferts, filtres, sites }) {
    const peut = usePeut();
    const filtrer = (changements) => router.get('/transferts', { ...filtres, ...changements, page: undefined }, { preserveState: true, replace: true });

    return (
        <AppLayout titre="Transferts entre sites" actions={peut('transferts.envoyer') && <Bouton href="/transferts/nouveau">Nouveau transfert</Bouton>}>
            <Carte>
                <div className="mb-4 flex flex-wrap gap-3">
                    <Liste value={filtres.site ?? ''} onChange={(e) => filtrer({ site: e.target.value || undefined })} className="max-w-64" aria-label="Site">
                        <option value="">Tous les sites</option>
                        {sites.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.nom}
                            </option>
                        ))}
                    </Liste>
                    <Liste value={filtres.statut ?? ''} onChange={(e) => filtrer({ statut: e.target.value || undefined })} className="max-w-48" aria-label="Statut">
                        <option value="">Tous statuts</option>
                        {Object.entries(statutsTransfert).map(([v, [l]]) => (
                            <option key={v} value={v}>
                                {l}
                            </option>
                        ))}
                    </Liste>
                </div>
                <Tableau
                    colonnes={[{ libelle: 'N°' }, { libelle: 'Envoyé le' }, { libelle: 'De' }, { libelle: 'Vers' }, { libelle: 'Reçus', droite: true }, { libelle: 'Statut' }, { libelle: 'Motif' }]}
                    vide={transferts.data.length === 0 ? 'Aucun transfert.' : null}
                >
                    {transferts.data.map((t) => (
                        <tr key={t.id} className="cursor-pointer hover:bg-slate-50" onClick={() => router.visit(`/transferts/${t.id}`)}>
                            <td className="px-5 py-2 font-medium">
                                <Link href={`/transferts/${t.id}`} className="text-green-800 hover:underline">
                                    {t.id}
                                </Link>
                            </td>
                            <td className="px-5 py-2 whitespace-nowrap">{dateFr(t.envoye_le)}</td>
                            <td className="px-5 py-2">{t.origine}</td>
                            <td className="px-5 py-2">{t.destination}</td>
                            <td className="px-5 py-2 text-right tabular-nums">
                                {t.recues} / {t.lignes}
                            </td>
                            <td className="px-5 py-2">
                                <StatutTransfert valeur={t.statut} />
                            </td>
                            <td className="max-w-64 truncate px-5 py-2 text-slate-600" title={t.motif}>
                                {t.motif}
                            </td>
                        </tr>
                    ))}
                </Tableau>
                <Pagination liens={transferts.links} />
            </Carte>
        </AppLayout>
    );
}
