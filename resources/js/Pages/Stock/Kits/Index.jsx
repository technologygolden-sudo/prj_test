import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../Layouts/AppLayout';
import { Bouton, Carte, Liste, Pagination, Saisie, Statut, Tableau, dateFr, usePeut } from '../../../Components/ui';

export default function Index({ kits, filtres }) {
    const peut = usePeut();
    const [q, setQ] = useState(filtres.q ?? '');
    const filtrer = (changements) => router.get('/kits-assembles', { ...filtres, q: q || undefined, ...changements }, { preserveState: true, replace: true });

    return (
        <AppLayout titre="Kits assemblés" actions={peut('assemblage.realiser') && <Bouton href="/assemblage">Assembler un kit</Bouton>}>
            <Carte>
                <form
                    className="mb-4 flex flex-wrap gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        filtrer({ page: undefined });
                    }}
                >
                    <Saisie type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="UID, n° de série, référence" className="max-w-xs" aria-label="Rechercher" />
                    <Liste value={filtres.statut ?? ''} onChange={(e) => filtrer({ statut: e.target.value || undefined, page: undefined })} className="max-w-44" aria-label="Statut">
                        <option value="">Tous statuts</option>
                        <option value="assemble">Assemblé</option>
                        <option value="expedie">Expédié</option>
                        <option value="desassemble">Désassemblé</option>
                    </Liste>
                    <Bouton type="submit" variante="secondaire">
                        Rechercher
                    </Bouton>
                </form>
                <Tableau
                    colonnes={[{ libelle: 'N° de série' }, { libelle: 'Type de kit' }, { libelle: 'Version' }, { libelle: 'Statut' }, { libelle: 'Assemblé le' }, { libelle: 'Par' }]}
                    vide={kits.data.length === 0 ? 'Aucun kit assemblé.' : null}
                >
                    {kits.data.map((k) => (
                        <tr key={k.id} className="hover:bg-slate-50">
                            <td className="px-5 py-2 font-medium">
                                <Link href={`/kits-assembles/${k.id}`} className="text-green-800 hover:underline">
                                    {k.numero_serie}
                                </Link>
                            </td>
                            <td className="px-5 py-2">
                                {k.reference} <span className="text-slate-500">· {k.nom}</span>
                            </td>
                            <td className="px-5 py-2">v{k.version}</td>
                            <td className="px-5 py-2">
                                <Statut valeur={k.statut} />
                            </td>
                            <td className="px-5 py-2">{dateFr(k.assemble_le)}</td>
                            <td className="px-5 py-2 text-slate-600">{k.assembleur}</td>
                        </tr>
                    ))}
                </Tableau>
                <Pagination liens={kits.links} />
            </Carte>
        </AppLayout>
    );
}
