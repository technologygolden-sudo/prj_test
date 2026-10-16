import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../Layouts/AppLayout';
import PanneauImpression from '../../../Components/PanneauImpression';
import { EtatPastille } from '../../../Components/ActionsElement';
import { Bouton, Carte, Liste, Pagination, Pastille, Saisie, Statut, Tableau, classes, dateFr, usePeut } from '../../../Components/ui';

export default function Index({ kits, filtres, imprimantes }) {
    const peut = usePeut();
    const [q, setQ] = useState(filtres.q ?? '');
    const [selection, setSelection] = useState([]);
    const filtrer = (changements) => router.get('/kits-assembles', { ...filtres, q: q || undefined, ...changements }, { preserveState: true, replace: true });
    const imprimables = kits.data.filter((k) => k.statut === 'assemble');
    const choisis = imprimables.filter((k) => selection.includes(k.id));

    return (
        <AppLayout titre="Kits assemblés" actions={peut('assemblage.realiser') && <Bouton href="/assemblage">Assembler un kit</Bouton>}>
            <div className="space-y-4">
                {choisis.length > 0 && (
                    <Carte titre={`Étiquettes de ${choisis.length} kit(s) sélectionné(s)`}>
                        <PanneauImpression cibles={choisis.map((k) => ({ type: 'kit', id: k.id }))} imprimantes={imprimantes} dejaImprime libelle="Imprimer" />
                    </Carte>
                )}
                <Carte>
                    <form
                        className="mb-4 flex flex-wrap gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            filtrer({ page: undefined });
                        }}
                    >
                        <Saisie type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="UID, n° de série, référence" className="max-w-xs" aria-label="Rechercher" />
                        <Liste value={filtres.statut ?? ''} onChange={(e) => filtrer({ statut: e.target.value || undefined, page: undefined })} className="max-w-48" aria-label="Statut">
                            <option value="">Tous statuts</option>
                            <option value="assemble">Assemblé</option>
                            <option value="en_transit">En transit</option>
                            <option value="expedie">Remis au technicien</option>
                            <option value="desassemble">Désassemblé</option>
                        </Liste>
                        <Bouton type="submit" variante="secondaire">
                            Rechercher
                        </Bouton>
                    </form>
                    <Tableau
                        colonnes={[{ libelle: '' }, { libelle: 'N° de série' }, { libelle: 'Type de kit' }, { libelle: 'Emplacement' }, { libelle: 'Statut' }, { libelle: 'Assemblé le' }]}
                        vide={kits.data.length === 0 ? 'Aucun kit assemblé.' : null}
                    >
                        {kits.data.map((k) => (
                            <tr key={k.id} className={classes('hover:bg-slate-50', selection.includes(k.id) && 'bg-green-50/60')}>
                                <td className="px-5 py-2">
                                    {k.statut === 'assemble' && (
                                        <input
                                            type="checkbox"
                                            checked={selection.includes(k.id)}
                                            onChange={() => setSelection(selection.includes(k.id) ? selection.filter((x) => x !== k.id) : [...selection, k.id])}
                                            aria-label={`Sélectionner ${k.numero_serie}`}
                                        />
                                    )}
                                </td>
                                <td className="px-5 py-2 font-medium">
                                    <Link href={`/kits-assembles/${k.id}`} className="text-green-800 hover:underline">
                                        {k.numero_serie}
                                    </Link>
                                </td>
                                <td className="px-5 py-2">
                                    {k.reference} <span className="text-slate-500">· {k.nom} · v{k.version}</span>
                                </td>
                                <td className="px-5 py-2 text-slate-600">
                                    {k.emplacement_libelle || '—'} <Pastille>{k.proprietaire}</Pastille>
                                </td>
                                <td className="px-5 py-2">
                                    <div className="flex flex-wrap gap-1">
                                        <Statut valeur={k.statut} />
                                        {k.etat === 'defectueux' && <EtatPastille etat="defectueux" />}
                                    </div>
                                </td>
                                <td className="px-5 py-2">{dateFr(k.assemble_le)}</td>
                            </tr>
                        ))}
                    </Tableau>
                    <Pagination liens={kits.links} />
                </Carte>
            </div>
        </AppLayout>
    );
}
