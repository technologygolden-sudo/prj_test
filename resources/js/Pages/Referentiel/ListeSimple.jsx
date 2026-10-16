import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import { Bouton, Carte, Champ, Saisie, Tableau } from '../../Components/ui';

/** Catégories et fabricants : une seule colonne éditable sur place. */
function Ligne({ element, route }) {
    const [edition, setEdition] = useState(false);
    const { data, setData, put, processing, errors } = useForm({ valeur: element.valeur });

    const supprimer = () => {
        if (confirm(`Supprimer « ${element.valeur} » ?`)) {
            router.delete(`/${route}/${element.id}`, { preserveScroll: true });
        }
    };

    return (
        <tr>
            <td className="px-5 py-2">
                {edition ? (
                    <form
                        className="flex items-start gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            put(`/${route}/${element.id}`, { preserveScroll: true, onSuccess: () => setEdition(false) });
                        }}
                    >
                        <Champ erreur={errors.valeur} className="flex-1">
                            <Saisie autoFocus value={data.valeur} onChange={(e) => setData('valeur', e.target.value)} aria-label="Nouvelle valeur" />
                        </Champ>
                        <Bouton type="submit" disabled={processing}>
                            Enregistrer
                        </Bouton>
                        <Bouton variante="discret" onClick={() => setEdition(false)}>
                            Annuler
                        </Bouton>
                    </form>
                ) : (
                    element.valeur
                )}
            </td>
            <td className="px-5 py-2 text-right text-slate-600 tabular-nums">{element.utilisations}</td>
            <td className="px-5 py-2 text-right whitespace-nowrap">
                {!edition && (
                    <>
                        <Bouton variante="discret" onClick={() => setEdition(true)}>
                            Modifier
                        </Bouton>
                        <Bouton
                            variante="discret"
                            onClick={supprimer}
                            disabled={element.utilisations > 0}
                            title={element.utilisations > 0 ? 'Utilisé : suppression impossible' : undefined}
                        >
                            Supprimer
                        </Bouton>
                    </>
                )}
            </td>
        </tr>
    );
}

export default function ListeSimple({ titre, libelleChamp, route, elements }) {
    const { data, setData, post, processing, errors, reset } = useForm({ valeur: '' });

    return (
        <AppLayout titre={titre}>
            <div className="grid gap-6 lg:grid-cols-3">
                <Carte titre="Ajouter" className="lg:order-2">
                    <form
                        className="space-y-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            post(`/${route}`, { preserveScroll: true, onSuccess: () => reset() });
                        }}
                    >
                        <Champ libelle={libelleChamp} htmlFor="valeur" erreur={errors.valeur}>
                            <Saisie id="valeur" value={data.valeur} onChange={(e) => setData('valeur', e.target.value)} />
                        </Champ>
                        <Bouton type="submit" disabled={processing}>
                            Ajouter
                        </Bouton>
                    </form>
                </Carte>
                <Carte className="lg:col-span-2">
                    <Tableau
                        colonnes={[{ libelle: libelleChamp }, { libelle: 'Utilisations', droite: true }, { libelle: '' }]}
                        vide={elements.length === 0 ? 'Aucun élément pour l’instant.' : null}
                    >
                        {elements.map((el) => (
                            <Ligne key={el.id} element={el} route={route} />
                        ))}
                    </Tableau>
                </Carte>
            </div>
        </AppLayout>
    );
}
