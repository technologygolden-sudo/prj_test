import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import { Bouton, Carte, Champ, Pastille, Saisie, Tableau, classes } from '../../Components/ui';

function Ligne({ valeur }) {
    const [edition, setEdition] = useState(false);
    const { data, setData, put, processing, errors } = useForm({ libelle: valeur.libelle, actif: valeur.actif, ordre: valeur.ordre });

    return (
        <tr className={valeur.actif ? '' : 'text-slate-400'}>
            <td className="px-5 py-1.5">
                {edition ? (
                    <div className="flex flex-wrap items-start gap-2">
                        <Champ erreur={errors.libelle} className="min-w-64 flex-1">
                            <Saisie autoFocus value={data.libelle} onChange={(e) => setData('libelle', e.target.value)} aria-label="Libellé" />
                        </Champ>
                        <div className="w-20">
                            <Saisie type="number" min={0} value={data.ordre} onChange={(e) => setData('ordre', e.target.value)} aria-label="Ordre" title="Ordre d’affichage" />
                        </div>
                        <label className="flex items-center gap-1.5 pt-2 text-sm text-slate-700">
                            <input type="checkbox" checked={data.actif} onChange={(e) => setData('actif', e.target.checked)} />
                            Active
                        </label>
                        <Bouton disabled={processing} onClick={() => put(`/listes/${valeur.id}`, { preserveScroll: true, onSuccess: () => setEdition(false) })}>
                            Enregistrer
                        </Bouton>
                        <Bouton variante="discret" onClick={() => setEdition(false)}>
                            Annuler
                        </Bouton>
                    </div>
                ) : (
                    <>
                        {valeur.libelle} {!valeur.actif && <Pastille>Désactivée</Pastille>}
                    </>
                )}
            </td>
            <td className="px-5 py-1.5 text-right whitespace-nowrap">
                {!edition && (
                    <>
                        <Bouton variante="discret" onClick={() => setEdition(true)}>
                            Modifier
                        </Bouton>
                        <Bouton variante="discret" onClick={() => confirm(`Supprimer « ${valeur.libelle} » ?`) && router.delete(`/listes/${valeur.id}`, { preserveScroll: true })}>
                            Supprimer
                        </Bouton>
                    </>
                )}
            </td>
        </tr>
    );
}

export default function ListesValeurs({ listes, liste, valeurs }) {
    const courante = listes.find((l) => l.code === liste);
    const { data, setData, post, processing, errors, reset } = useForm({ liste, libelle: '' });

    return (
        <AppLayout titre="Listes de valeurs">
            <div className="grid gap-6 lg:grid-cols-4">
                <nav className="space-y-1 lg:col-span-1" aria-label="Listes">
                    {listes.map((l) => (
                        <Link
                            key={l.code}
                            href={`/listes?liste=${l.code}`}
                            preserveState={false}
                            className={classes('flex items-center justify-between rounded-md px-3 py-2 text-sm', l.code === liste ? 'bg-green-800 text-white' : 'text-slate-700 hover:bg-slate-100')}
                        >
                            {l.titre}
                            <span className={classes('text-xs', l.code === liste ? 'text-green-100' : 'text-slate-400')}>{l.nombre}</span>
                        </Link>
                    ))}
                    <p className="px-3 pt-3 text-xs text-slate-500">
                        Catégories et fabricants : écrans <Link href="/categories" className="text-green-800 hover:underline">Catégories</Link> et{' '}
                        <Link href="/fabricants" className="text-green-800 hover:underline">Fabricants</Link>.
                    </p>
                </nav>
                <div className="space-y-4 lg:col-span-3">
                    <Carte titre={courante?.titre}>
                        <p className="mb-4 text-sm text-slate-600">{courante?.aide}</p>
                        <form
                            className="mb-4 flex flex-wrap items-start gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                post('/listes', { preserveScroll: true, onSuccess: () => reset('libelle') });
                            }}
                        >
                            <Champ erreur={errors.libelle} className="min-w-64 flex-1">
                                <Saisie value={data.libelle} onChange={(e) => setData('libelle', e.target.value)} placeholder="Nouvelle valeur" aria-label="Nouvelle valeur" />
                            </Champ>
                            <Bouton type="submit" disabled={processing || !data.libelle.trim()}>
                                Ajouter
                            </Bouton>
                        </form>
                        <Tableau colonnes={[{ libelle: 'Valeur' }, { libelle: '' }]} vide={valeurs.length === 0 ? 'Aucune valeur pour l’instant.' : null}>
                            {valeurs.map((v) => (
                                <Ligne key={`${v.id}-${v.libelle}-${v.actif}-${v.ordre}`} valeur={v} />
                            ))}
                        </Tableau>
                    </Carte>
                </div>
            </div>
        </AppLayout>
    );
}
