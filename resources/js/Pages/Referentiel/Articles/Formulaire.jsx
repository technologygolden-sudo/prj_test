import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../Layouts/AppLayout';
import { Bouton, Carte, Champ, Liste, Saisie } from '../../../Components/ui';

export default function Formulaire({ article, categories, fabricants, tousArticles }) {
    const creation = article === null;
    const { data, setData, post, put, transform, processing, errors } = useForm({
        reference: article?.reference ?? '',
        nom: article?.nom ?? '',
        type: article?.type ?? '',
        est_serialise: article?.est_serialise ?? false,
        categorie_id: article?.categorie_id ?? '',
        fabricant_id: article?.fabricant_id ?? '',
        associes: article?.associes ?? [],
    });
    const [ajout, setAjout] = useState('');

    const envoyer = (e) => {
        e.preventDefault();
        transform((d) => ({ ...d, categorie_id: d.categorie_id || null, fabricant_id: d.fabricant_id || null, type: d.type || null }));
        if (creation) {
            post('/articles');
        } else {
            put(`/articles/${article.id}`);
        }
    };

    const candidats = tousArticles.filter((a) => a.id !== article?.id && !data.associes.includes(a.id));
    const libelle = (id) => {
        const a = tousArticles.find((x) => x.id === id);
        return a ? `${a.reference} — ${a.nom}` : id;
    };

    return (
        <AppLayout titre={creation ? 'Nouvel article' : `Article ${article.reference}`}>
            <form onSubmit={envoyer} className="space-y-6">
                <Carte titre="Identification">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Champ libelle="Référence CRM" htmlFor="reference" erreur={errors.reference}>
                            <Saisie id="reference" value={data.reference} onChange={(e) => setData('reference', e.target.value)} invalide={errors.reference} />
                        </Champ>
                        <Champ libelle="Désignation" htmlFor="nom" erreur={errors.nom}>
                            <Saisie id="nom" value={data.nom} onChange={(e) => setData('nom', e.target.value)} invalide={errors.nom} />
                        </Champ>
                        <Champ libelle="Catégorie" htmlFor="categorie_id" erreur={errors.categorie_id}>
                            <Liste id="categorie_id" value={data.categorie_id ?? ''} onChange={(e) => setData('categorie_id', e.target.value)}>
                                <option value="">—</option>
                                {categories.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.libelle}
                                    </option>
                                ))}
                            </Liste>
                        </Champ>
                        <Champ libelle="Fabricant" htmlFor="fabricant_id" erreur={errors.fabricant_id}>
                            <Liste id="fabricant_id" value={data.fabricant_id ?? ''} onChange={(e) => setData('fabricant_id', e.target.value)} invalide={errors.fabricant_id}>
                                <option value="">—</option>
                                {fabricants.map((f) => (
                                    <option key={f.id} value={f.id}>
                                        {f.nom}
                                    </option>
                                ))}
                            </Liste>
                        </Champ>
                        <Champ libelle="Type" htmlFor="type" erreur={errors.type} aide="Valeurs encore à définir (dossier v2, section 2.1).">
                            <Saisie id="type" value={data.type ?? ''} onChange={(e) => setData('type', e.target.value)} />
                        </Champ>
                        <Champ libelle="Suivi" erreur={errors.est_serialise}>
                            <label className="mt-2 flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" checked={data.est_serialise} onChange={(e) => setData('est_serialise', e.target.checked)} />
                                Article sérialisé (un n° de série par unité)
                            </label>
                        </Champ>
                    </div>
                </Carte>

                <Carte titre="Produits associés">
                    <p className="mb-3 text-sm text-slate-600">Accessoires ou articles compatibles. Sans effet sur les nomenclatures.</p>
                    <div className="mb-3 flex flex-wrap gap-2">
                        {data.associes.length === 0 && <span className="text-sm text-slate-500">Aucun.</span>}
                        {data.associes.map((id) => (
                            <span key={id} className="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-1 text-sm">
                                {libelle(id)}
                                <button
                                    type="button"
                                    className="text-slate-500 hover:text-red-700"
                                    aria-label={`Retirer ${libelle(id)}`}
                                    onClick={() => setData('associes', data.associes.filter((x) => x !== id))}
                                >
                                    ×
                                </button>
                            </span>
                        ))}
                    </div>
                    <div className="flex max-w-lg gap-2">
                        <Liste value={ajout} onChange={(e) => setAjout(e.target.value)} aria-label="Article à associer">
                            <option value="">Choisir un article…</option>
                            {candidats.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.reference} — {a.nom}
                                </option>
                            ))}
                        </Liste>
                        <Bouton
                            variante="secondaire"
                            disabled={!ajout}
                            onClick={() => {
                                setData('associes', [...data.associes, Number(ajout)]);
                                setAjout('');
                            }}
                        >
                            Ajouter
                        </Bouton>
                    </div>
                    {errors.associes && <p className="mt-1 text-sm text-red-700">{errors.associes}</p>}
                </Carte>

                <div className="flex flex-wrap justify-between gap-3">
                    <div className="flex gap-2">
                        <Bouton type="submit" disabled={processing}>
                            {creation ? 'Créer l’article' : 'Enregistrer'}
                        </Bouton>
                        <Bouton variante="secondaire" href="/articles">
                            Annuler
                        </Bouton>
                    </div>
                    {!creation && (
                        <Bouton
                            variante="danger"
                            onClick={() => confirm(`Supprimer l’article ${article.reference} ?`) && router.delete(`/articles/${article.id}`)}
                        >
                            Supprimer
                        </Bouton>
                    )}
                </div>
            </form>
        </AppLayout>
    );
}
