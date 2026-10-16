import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../Layouts/AppLayout';
import { Bouton, Carte, Liste, Pagination, Pastille, Saisie, Tableau, usePeut } from '../../../Components/ui';

export default function Index({ articles, filtres, categories }) {
    const peut = usePeut();
    const [q, setQ] = useState(filtres.q ?? '');

    const filtrer = (changements) =>
        router.get('/articles', { ...filtres, q, ...changements }, { preserveState: true, replace: true });

    return (
        <AppLayout titre="Articles" actions={peut('articles.gerer') && <Bouton href="/articles/nouveau">Nouvel article</Bouton>}>
            <Carte>
                <form
                    className="mb-4 flex flex-wrap gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        filtrer({ page: undefined });
                    }}
                >
                    <Saisie type="search" placeholder="Référence ou nom" value={q} onChange={(e) => setQ(e.target.value)} className="max-w-xs" aria-label="Rechercher" />
                    <Liste value={filtres.categorie ?? ''} onChange={(e) => filtrer({ categorie: e.target.value || undefined, page: undefined })} className="max-w-48" aria-label="Catégorie">
                        <option value="">Toutes catégories</option>
                        {categories.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.libelle}
                            </option>
                        ))}
                    </Liste>
                    <Liste value={filtres.serialise ?? ''} onChange={(e) => filtrer({ serialise: e.target.value || undefined, page: undefined })} className="max-w-44" aria-label="Sérialisation">
                        <option value="">Sérialisés ou non</option>
                        <option value="oui">Sérialisés</option>
                        <option value="non">Non sérialisés</option>
                    </Liste>
                    <Bouton type="submit" variante="secondaire">
                        Rechercher
                    </Bouton>
                </form>
                <Tableau
                    colonnes={[{ libelle: 'Référence' }, { libelle: 'Nom' }, { libelle: 'Catégorie' }, { libelle: 'Fabricant' }, { libelle: 'Suivi' }]}
                    vide={articles.data.length === 0 ? 'Aucun article ne correspond.' : null}
                >
                    {articles.data.map((a) => (
                        <tr key={a.id} className="hover:bg-slate-50">
                            <td className="px-5 py-2 font-medium">
                                {peut('articles.gerer') ? (
                                    <Link href={`/articles/${a.id}/modifier`} className="text-green-800 hover:underline">
                                        {a.reference}
                                    </Link>
                                ) : (
                                    a.reference
                                )}
                            </td>
                            <td className="px-5 py-2">{a.nom}</td>
                            <td className="px-5 py-2 text-slate-600">{a.categorie?.libelle ?? '—'}</td>
                            <td className="px-5 py-2 text-slate-600">{a.fabricant?.nom ?? '—'}</td>
                            <td className="px-5 py-2">{a.est_serialise ? <Pastille couleur="vert">N° de série</Pastille> : <Pastille>Lot</Pastille>}</td>
                        </tr>
                    ))}
                </Tableau>
                <Pagination liens={articles.links} />
            </Carte>
        </AppLayout>
    );
}
