import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../Layouts/AppLayout';
import { Bouton, Carte, Pagination, Pastille, Saisie, Tableau, usePeut } from '../../../Components/ui';

export default function Index({ types, filtres }) {
    const peut = usePeut();
    const [q, setQ] = useState(filtres.q ?? '');

    return (
        <AppLayout titre="Types de kit" actions={peut('kits.gerer') && <Bouton href="/kits/nouveau">Nouveau type de kit</Bouton>}>
            <Carte>
                <form
                    className="mb-4 flex gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get('/kits', { q: q || undefined }, { preserveState: true, replace: true });
                    }}
                >
                    <Saisie type="search" placeholder="Référence, PartNo ou nom" value={q} onChange={(e) => setQ(e.target.value)} className="max-w-xs" aria-label="Rechercher" />
                    <Bouton type="submit" variante="secondaire">
                        Rechercher
                    </Bouton>
                </form>
                <Tableau
                    colonnes={[{ libelle: 'Référence' }, { libelle: 'PartNo' }, { libelle: 'Nom' }, { libelle: 'Catégorie' }, { libelle: 'Version active' }, { libelle: 'Brouillon' }]}
                    vide={types.data.length === 0 ? 'Aucun type de kit.' : null}
                >
                    {types.data.map((t) => (
                        <tr key={t.id} className="hover:bg-slate-50">
                            <td className="px-5 py-2 font-medium">
                                <Link href={`/kits/${t.id}`} className="text-green-800 hover:underline">
                                    {t.reference}
                                </Link>
                            </td>
                            <td className="px-5 py-2 text-slate-600">{t.partno}</td>
                            <td className="px-5 py-2">{t.nom}</td>
                            <td className="px-5 py-2 text-slate-600">{t.categorie ?? '—'}</td>
                            <td className="px-5 py-2">{t.version_active ? <Pastille couleur="vert">v{t.version_active}</Pastille> : <Pastille couleur="orange">Aucune</Pastille>}</td>
                            <td className="px-5 py-2">{t.brouillon ? <Pastille>v{t.brouillon}</Pastille> : '—'}</td>
                        </tr>
                    ))}
                </Tableau>
                <Pagination liens={types.links} />
            </Carte>
        </AppLayout>
    );
}
