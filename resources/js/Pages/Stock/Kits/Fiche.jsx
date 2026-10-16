import { Link, useForm } from '@inertiajs/react';
import AppLayout from '../../../Layouts/AppLayout';
import PanneauImpression from '../../../Components/PanneauImpression';
import { Impressions } from '../../../Components/Historique';
import { Bouton, Carte, Champ, Saisie, Statut, Tableau, dateFr, usePeut } from '../../../Components/ui';

function Desassemblage({ kit }) {
    const { data, setData, post, processing, errors } = useForm({ motif: '' });
    return (
        <form
            className="flex flex-wrap items-end gap-3"
            onSubmit={(e) => {
                e.preventDefault();
                if (confirm(`Désassembler le kit ${kit.numero_serie} ? Ses composants repasseront en stock.`)) {
                    post(`/kits-assembles/${kit.id}/desassembler`, { preserveScroll: true });
                }
            }}
        >
            <Champ libelle="Motif" erreur={errors.motif} className="min-w-72 flex-1">
                <Saisie value={data.motif} onChange={(e) => setData('motif', e.target.value)} placeholder="Composant défectueux, erreur d’assemblage…" />
            </Champ>
            <Bouton type="submit" variante="danger" disabled={processing || data.motif.trim().length < 5}>
                Désassembler
            </Bouton>
        </form>
    );
}

export default function Fiche({ kit, contenu, impressions, imprimantes }) {
    const peut = usePeut();

    return (
        <AppLayout titre={`Kit ${kit.reference} · S/N ${kit.numero_serie}`} actions={<Bouton variante="secondaire" href="/kits-assembles">Kits assemblés</Bouton>}>
            <div className="space-y-6">
                <Carte>
                    <dl className="grid gap-4 text-sm sm:grid-cols-3 lg:grid-cols-4">
                        {[
                            ['UID', <span className="font-mono text-xs">{kit.uid}</span>],
                            ['Type de kit', `${kit.reference} · ${kit.nom}`],
                            ['PartNo / version', `${kit.partno} · v${kit.version}`],
                            ['Statut', <Statut valeur={kit.statut} />],
                            ['Assemblé le', dateFr(kit.assemble_le)],
                            ['Par', kit.assembleur],
                            ...(kit.statut === 'desassemble' ? [['Désassemblé le', dateFr(kit.desassemble_le)], ['Motif', kit.motif_desassemblage]] : []),
                        ].map(([l, v]) => (
                            <div key={l}>
                                <dt className="text-slate-500">{l}</dt>
                                <dd className="font-medium">{v}</dd>
                            </div>
                        ))}
                    </dl>
                </Carte>
                {kit.statut === 'assemble' && (
                    <Carte titre="Étiquette du kit">
                        <PanneauImpression cibles={[{ type: 'kit', id: kit.id }]} imprimantes={imprimantes} dejaImprime={impressions.length > 0} />
                        <div className="mt-4">
                            <Impressions impressions={impressions} />
                        </div>
                    </Carte>
                )}
                <Carte titre="Composants">
                    <Tableau colonnes={[{ libelle: 'Article' }, { libelle: 'N° de série / lot' }, { libelle: 'UID' }, { libelle: 'Quantité', droite: true }]}>
                        {contenu.map((c) => (
                            <tr key={c.id}>
                                <td className="px-5 py-2">
                                    {c.reference} <span className="text-slate-500">· {c.nom}</span>
                                </td>
                                <td className="px-5 py-2">{c.numero_serie ?? 'Lot'}</td>
                                <td className="px-5 py-2 font-mono text-xs">
                                    <Link href={`/stock/${c.id}`} className="text-green-800 hover:underline">
                                        {c.uid}
                                    </Link>
                                </td>
                                <td className="px-5 py-2 text-right tabular-nums">{c.quantite}</td>
                            </tr>
                        ))}
                    </Tableau>
                </Carte>
                {kit.statut === 'assemble' && peut('assemblage.annuler') && (
                    <Carte titre="Désassemblage">
                        <Desassemblage kit={kit} />
                    </Carte>
                )}
            </div>
        </AppLayout>
    );
}
