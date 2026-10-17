import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../Layouts/AppLayout';
import { Bouton, Carte, Champ, Saisie } from '../../../Components/ui';

/** Liste de cases à cocher filtrable (sites, catégories, articles, types de kit). */
function Choix({ titre, elements, valeur, onChange, libelle, filtrable = false }) {
    const [filtre, setFiltre] = useState('');
    const visibles = elements.filter((e) => !filtre || libelle(e).toLowerCase().includes(filtre.toLowerCase()));
    return (
        <div>
            <div className="mb-1 flex items-center justify-between gap-2">
                <p className="text-sm font-medium text-slate-700">
                    {titre} <span className="font-normal text-slate-500">({valeur.length})</span>
                </p>
                <div className="flex gap-2 text-xs">
                    <button type="button" className="text-green-800 hover:underline" onClick={() => onChange([...new Set([...valeur, ...visibles.map((e) => e.id)])])}>
                        Tout
                    </button>
                    <button type="button" className="text-slate-500 hover:underline" onClick={() => onChange([])}>
                        Aucun
                    </button>
                </div>
            </div>
            {filtrable && <Saisie value={filtre} onChange={(e) => setFiltre(e.target.value)} placeholder="Filtrer…" className="mb-1" aria-label={`Filtrer ${titre}`} />}
            <div className="max-h-56 overflow-y-auto rounded-md p-2 ring-1 ring-slate-200">
                {visibles.map((e) => (
                    <label key={e.id} className="flex items-center gap-2 py-0.5 text-sm text-slate-700">
                        <input type="checkbox" checked={valeur.includes(e.id)} onChange={(ev) => onChange(ev.target.checked ? [...valeur, e.id] : valeur.filter((x) => x !== e.id))} />
                        {libelle(e)}
                    </label>
                ))}
                {visibles.length === 0 && <p className="text-xs text-slate-400">Aucun élément.</p>}
            </div>
        </div>
    );
}

export default function Parametres({ inventaire, sites, categories, articles, typesKit }) {
    const creation = inventaire === null;
    const { data, setData, post, put, processing, errors } = useForm({
        libelle: inventaire?.libelle ?? '',
        date_prevue: inventaire?.date_prevue?.slice(0, 10) ?? new Date().toISOString().slice(0, 10),
        commentaire: inventaire?.commentaire ?? '',
        sites: inventaire?.sites ?? [],
        tous_articles: inventaire?.tous_articles ?? false,
        tous_kits: inventaire?.tous_kits ?? false,
        categories: inventaire?.categories ?? [],
        articles: inventaire?.articles ?? [],
        types_kit: inventaire?.types_kit ?? [],
    });

    return (
        <AppLayout titre={creation ? 'Nouvelle campagne d’inventaire' : `Paramètres : ${inventaire.libelle}`}>
            <form
                className="space-y-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    creation ? post('/inventaires') : put(`/inventaires/${inventaire.id}`);
                }}
            >
                <Carte titre="Campagne">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Champ libelle="Libellé" erreur={errors.libelle} className="sm:col-span-2">
                            <Saisie value={data.libelle} onChange={(e) => setData('libelle', e.target.value)} placeholder="Inventaire annuel 2026, inventaire tournant SHS…" />
                        </Champ>
                        <Champ libelle="Date" erreur={errors.date_prevue}>
                            <Saisie type="date" value={data.date_prevue} onChange={(e) => setData('date_prevue', e.target.value)} />
                        </Champ>
                        <Champ libelle="Commentaire" erreur={errors.commentaire} className="sm:col-span-3">
                            <Saisie value={data.commentaire} onChange={(e) => setData('commentaire', e.target.value)} placeholder="Consignes pour les compteurs" />
                        </Champ>
                    </div>
                </Carte>

                <Carte titre="Sites inventoriés">
                    <Choix titre="Entrepôt et centres de service" elements={sites} valeur={data.sites} onChange={(v) => setData('sites', v)} libelle={(s) => s.nom} />
                    {errors.sites && <p className="mt-1 text-sm text-red-700">{errors.sites}</p>}
                </Carte>

                <Carte titre="Périmètre : gamme d’articles ou de kits">
                    <div className="mb-4 flex flex-wrap gap-6">
                        <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                            <input type="checkbox" checked={data.tous_articles} onChange={(e) => setData('tous_articles', e.target.checked)} />
                            Tous les articles
                        </label>
                        <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                            <input type="checkbox" checked={data.tous_kits} onChange={(e) => setData('tous_kits', e.target.checked)} />
                            Tous les kits montés
                        </label>
                    </div>
                    <div className="grid gap-4 lg:grid-cols-3">
                        <Choix titre="Catégories (articles et kits)" elements={categories} valeur={data.categories} onChange={(v) => setData('categories', v)} libelle={(c) => c.libelle} />
                        {!data.tous_articles && (
                            <Choix titre="Articles" elements={articles} valeur={data.articles} onChange={(v) => setData('articles', v)} libelle={(a) => `${a.reference} · ${a.nom}`} filtrable />
                        )}
                        {!data.tous_kits && (
                            <Choix titre="Types de kit" elements={typesKit} valeur={data.types_kit} onChange={(v) => setData('types_kit', v)} libelle={(k) => `${k.reference} · ${k.nom}`} filtrable />
                        )}
                    </div>
                </Carte>

                <div className="flex gap-2">
                    <Bouton type="submit" disabled={processing}>
                        {creation ? 'Créer la campagne' : 'Enregistrer'}
                    </Bouton>
                    <Bouton variante="secondaire" href={creation ? '/inventaires' : `/inventaires/${inventaire.id}`}>
                        Annuler
                    </Bouton>
                </div>
            </form>
        </AppLayout>
    );
}
