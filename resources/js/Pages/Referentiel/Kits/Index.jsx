import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../Layouts/AppLayout';
import { Bouton, Carte, Liste, Pagination, Pastille, Saisie, Tableau, usePeut } from '../../../Components/ui';

export default function Index({ types, filtres }) {
    const peut = usePeut();
    const [q, setQ] = useState(filtres.q ?? '');
    const filtrer = (changements) => router.get('/kits', { q: q || undefined, etat: filtres.etat, ...changements }, { preserveState: true, replace: true });

    return (
        <AppLayout titre="Composition des kits" actions={peut('kits.gerer') && <Bouton href="/kits/nouveau">Nouveau kit</Bouton>}>
            <Carte>
                <p className="mb-4 text-sm text-slate-600">
                    Chaque kit a une référence CRM et une composition d’articles ; l’article porteur du n° de série donne son n° à chaque kit monté.
                </p>
                <form
                    className="mb-4 flex flex-wrap gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        filtrer({});
                    }}
                >
                    <Saisie type="search" placeholder="Référence CRM, PartNo ou nom" value={q} onChange={(e) => setQ(e.target.value)} className="max-w-xs" aria-label="Rechercher" />
                    <Liste value={filtres.etat ?? ''} onChange={(e) => filtrer({ etat: e.target.value || undefined })} className="max-w-60" aria-label="État de la composition">
                        <option value="">Tous les kits</option>
                        <option value="porteur">Porteur à choisir</option>
                        <option value="brouillon">Composition en brouillon</option>
                        <option value="actif">Prêts au montage</option>
                        <option value="vide">Sans composition</option>
                    </Liste>
                    <Bouton type="submit" variante="secondaire">
                        Rechercher
                    </Bouton>
                </form>
                <Tableau
                    colonnes={[{ libelle: 'Référence CRM' }, { libelle: 'Nom' }, { libelle: 'Catégorie' }, { libelle: 'Articles', droite: true }, { libelle: 'Porteur de série' }, { libelle: 'Composition' }]}
                    vide={types.data.length === 0 ? 'Aucun kit.' : null}
                >
                    {types.data.map((t) => (
                        <tr key={t.id} className="hover:bg-slate-50">
                            <td className="px-5 py-2 font-medium">
                                <Link href={`/kits/${t.id}`} className="text-green-800 hover:underline">
                                    {t.reference}
                                </Link>
                            </td>
                            <td className="px-5 py-2">{t.nom}</td>
                            <td className="px-5 py-2 text-slate-600">{t.categorie ?? '—'}</td>
                            <td className="px-5 py-2 text-right tabular-nums">{t.articles}</td>
                            <td className="px-5 py-2">{t.porteur ? <Pastille couleur="vert">{t.porteur}</Pastille> : t.articles > 0 ? <Pastille couleur="orange">À choisir</Pastille> : '—'}</td>
                            <td className="px-5 py-2">
                                {t.version_active ? <Pastille couleur="vert">Prête (v{t.version_active})</Pastille> : t.brouillon ? <Pastille couleur="orange">Brouillon v{t.brouillon}</Pastille> : <Pastille>Aucune</Pastille>}
                            </td>
                        </tr>
                    ))}
                </Tableau>
                <Pagination liens={types.links} />
            </Carte>
        </AppLayout>
    );
}
