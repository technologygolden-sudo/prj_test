import { Link, useForm } from '@inertiajs/react';
import AppLayout from '../../../Layouts/AppLayout';
import PanneauImpression from '../../../Components/PanneauImpression';
import { Impressions, Mouvements } from '../../../Components/Historique';
import ActionsElement from '../../../Components/ActionsElement';
import { Bouton, Carte, Champ, Saisie, Statut, dateFr, usePeut } from '../../../Components/ui';

function Ajustement({ unite }) {
    const serie = unite.nature === 'serie';
    const { data, setData, post, processing, errors, reset } = useForm({ quantite: serie ? (unite.statut === 'perdu' ? 1 : -1) : '', motif: '' });

    if (serie && !['en_stock', 'perdu'].includes(unite.statut)) {
        return <p className="text-sm text-slate-600">Ajustement impossible : l’unité est « {unite.statut} ».</p>;
    }

    return (
        <form
            className="flex flex-wrap items-end gap-3"
            onSubmit={(e) => {
                e.preventDefault();
                post(`/stock/${unite.id}/ajuster`, { preserveScroll: true, onSuccess: () => reset('motif') });
            }}
        >
            {!serie && (
                <Champ libelle="Écart (+ ou −)" erreur={errors.quantite} className="w-36">
                    <Saisie type="number" value={data.quantite} onChange={(e) => setData('quantite', e.target.value)} />
                </Champ>
            )}
            <Champ libelle="Motif" erreur={errors.motif ?? (serie ? errors.quantite : null)} className="min-w-64 flex-1">
                <Saisie value={data.motif} onChange={(e) => setData('motif', e.target.value)} placeholder="Constat d’inventaire du…" />
            </Champ>
            <Bouton type="submit" variante={serie && unite.statut === 'en_stock' ? 'danger' : 'primaire'} disabled={processing}>
                {serie ? (unite.statut === 'perdu' ? 'Déclarer retrouvée' : 'Déclarer perdue') : 'Ajuster'}
            </Bouton>
        </form>
    );
}

export default function Fiche({ unite, kitParent, mouvements, impressions, imprimantes, emplacement, emplacements, historique }) {
    const peut = usePeut();

    return (
        <AppLayout titre={`${unite.reference} · ${unite.numero_serie ? `S/N ${unite.numero_serie}` : 'Lot'}`} actions={<Bouton variante="secondaire" href="/stock">Stock</Bouton>}>
            <div className="space-y-6">
                <Carte>
                    <dl className="grid gap-4 text-sm sm:grid-cols-3 lg:grid-cols-4">
                        {[
                            ['UID', <span className="font-mono text-xs">{unite.uid}</span>],
                            ['Article', `${unite.reference} · ${unite.nom}`],
                            ['Statut', <Statut valeur={unite.statut} />],
                            ['Quantité', `${unite.quantite_restante} / ${unite.quantite_initiale}`],
                            ['Fabricant', unite.fabricant ?? '—'],
                            ['Réception', unite.num_bl ? <Link href={`/receptions/${unite.reception_id}`} className="text-green-800 hover:underline">{unite.num_bl} du {dateFr(unite.date_reception, false)}</Link> : '—'],
                            ['Repère de rangement', unite.emplacement ?? '—'],
                            ['Kit parent', kitParent ? <Link href={`/kits-assembles/${kitParent.id}`} className="text-green-800 hover:underline">S/N {kitParent.numero_serie}</Link> : '—'],
                        ].map(([l, v]) => (
                            <div key={l}>
                                <dt className="text-slate-500">{l}</dt>
                                <dd className="font-medium">{v}</dd>
                            </div>
                        ))}
                    </dl>
                </Carte>
                <ActionsElement type="unite" element={unite} emplacement={emplacement} emplacements={emplacements} historique={historique} disponible={unite.statut === 'en_stock'} />
                <Carte titre="Étiquette">
                    <PanneauImpression cibles={[{ type: 'unite', id: unite.id }]} imprimantes={imprimantes} dejaImprime={impressions.length > 0} />
                    <div className="mt-4">
                        <Impressions impressions={impressions} />
                    </div>
                </Carte>
                {peut('stock.ajuster') && (
                    <Carte titre="Ajustement d’inventaire">
                        <Ajustement unite={unite} />
                    </Carte>
                )}
                <Carte titre="Mouvements">
                    <Mouvements mouvements={mouvements} />
                </Carte>
            </div>
        </AppLayout>
    );
}
