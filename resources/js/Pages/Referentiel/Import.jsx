import { router, useForm } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { Bouton, Carte, Champ } from '../../Components/ui';

function Liste({ titre, elements, max = 12 }) {
    if (!elements?.length) {
        return null;
    }
    return (
        <details className="mt-2 text-sm">
            <summary className="cursor-pointer text-green-800">
                {titre} ({elements.length})
            </summary>
            <ul className="mt-1 max-h-64 list-disc overflow-y-auto pl-5 text-slate-700">
                {elements.slice(0, 500).map((e) => (
                    <li key={e}>{e}</li>
                ))}
            </ul>
            {elements.length > max && <span className="sr-only">{elements.length} éléments</span>}
        </details>
    );
}

function Chiffre({ libelle, valeur, alerte }) {
    return (
        <div className="rounded-lg bg-slate-50 p-3">
            <p className="text-xs text-slate-600">{libelle}</p>
            <p className={`text-lg font-semibold tabular-nums ${alerte ? 'text-amber-800' : 'text-slate-900'}`}>{valeur}</p>
        </div>
    );
}

function Apercu({ apercu }) {
    const confirmer = useForm({ jeton: apercu.jeton });

    if (apercu.erreurs.length > 0) {
        return (
            <Carte titre={`Fichier refusé : ${apercu.fichier}`}>
                <p className="mb-2 text-sm text-slate-700">Corrigez ces erreurs dans Excel puis chargez le fichier à nouveau. Rien n’a été importé.</p>
                <ul className="list-disc space-y-1 pl-5 text-sm text-red-700">
                    {apercu.erreurs.slice(0, 100).map((e) => (
                        <li key={e}>{e}</li>
                    ))}
                </ul>
            </Carte>
        );
    }

    const n = apercu.nomenclatures;
    return (
        <Carte
            titre={`Aperçu : ${apercu.fichier}`}
            actions={
                <>
                    <Bouton variante="secondaire" onClick={() => router.post('/import/annuler')}>
                        Annuler
                    </Bouton>
                    <Bouton disabled={confirmer.processing} onClick={() => confirmer.post('/import/appliquer')}>
                        {confirmer.processing ? 'Import en cours…' : 'Confirmer l’import'}
                    </Bouton>
                </>
            }
        >
            <p className="mb-4 text-sm text-slate-600">
                {apercu.lignes.articles} articles et {apercu.lignes.kits} kits lus. Rien n’est encore écrit : vérifiez puis confirmez.
            </p>
            <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <Chiffre libelle="Articles nouveaux" valeur={apercu.articles.nouveaux.length} />
                <Chiffre libelle="Articles modifiés" valeur={apercu.articles.modifies.length} />
                <Chiffre libelle="Kits nouveaux" valeur={apercu.kits.nouveaux.length} />
                <Chiffre libelle="Kits modifiés" valeur={apercu.kits.modifies.length} />
                <Chiffre libelle="Nomenclatures créées" valeur={n.nouvelles} />
                <Chiffre libelle="Nouvelles versions" valeur={n.nouvelles_versions + n.brouillons_mis_a_jour} />
            </div>
            <Liste titre="Articles nouveaux" elements={apercu.articles.nouveaux} />
            <Liste titre="Articles modifiés" elements={apercu.articles.modifies} />
            <Liste titre="Kits nouveaux" elements={apercu.kits.nouveaux} />
            <Liste titre="Kits modifiés" elements={apercu.kits.modifies} />
            <Liste titre="Fabricants créés" elements={apercu.fabricants_nouveaux} />
            {apercu.avertissements.length > 0 && (
                <div className="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200">
                    <p className="font-medium">À savoir ({apercu.avertissements.length})</p>
                    <ul className="mt-1 max-h-56 list-disc overflow-y-auto pl-5">
                        {apercu.avertissements.map((a) => (
                            <li key={a}>{a}</li>
                        ))}
                    </ul>
                </div>
            )}
            <p className="mt-4 text-xs text-slate-500">
                Les nomenclatures arrivent en brouillon, quantité 1 par article, sans porteur de série : choisissez-le sur la fiche de chaque kit avant de verrouiller.
                Une version déjà verrouillée n’est jamais modifiée : si sa liste d’articles change, une nouvelle version brouillon est créée.
            </p>
        </Carte>
    );
}

function Bilan({ bilan }) {
    const lignes = [
        ['Articles créés', bilan.articles_crees],
        ['Articles modifiés', bilan.articles_modifies],
        ['Kits créés', bilan.kits_crees],
        ['Kits modifiés', bilan.kits_modifies],
        ['Nomenclatures créées (brouillon)', bilan.nomenclatures_creees],
        ['Brouillons mis à jour', bilan.brouillons_mis_a_jour],
        ['Nouvelles versions brouillon', bilan.nouvelles_versions],
    ];
    return (
        <Carte titre={`Import terminé : ${bilan.fichier}`} actions={<Bouton href="/kits">Voir les kits</Bouton>}>
            <dl className="grid gap-3 sm:grid-cols-4">
                {lignes.map(([l, v]) => (
                    <Chiffre key={l} libelle={l} valeur={v} />
                ))}
            </dl>
            {bilan.serialisation_conservee > 0 && (
                <p className="mt-3 text-sm text-amber-800">{bilan.serialisation_conservee} article(s) porteur(s) de série sont restés sérialisés (RG05).</p>
            )}
            <p className="mt-3 text-sm text-slate-600">Prochaine étape : sur chaque kit, choisir l’article porteur de série puis verrouiller la version.</p>
        </Carte>
    );
}

export default function Import({ apercu, bilan }) {
    const { data, setData, post, processing, errors, reset } = useForm({ fichier: null });

    return (
        <AppLayout titre="Import Excel du référentiel">
            <div className="space-y-6">
                <Carte titre="Charger un fichier">
                    <form
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            post('/import/analyser', { forceFormData: true, onSuccess: () => reset() });
                        }}
                    >
                        <Champ libelle="Classeur .xlsx" erreur={errors.fichier} aide="Feuilles « products_parts » (articles) et « products_serialized » (kits), comme l’export du CRM.">
                            <input type="file" accept=".xlsx" onChange={(e) => setData('fichier', e.target.files[0] ?? null)} className="text-sm" />
                        </Champ>
                        <Bouton type="submit" disabled={!data.fichier || processing}>
                            {processing ? 'Analyse…' : 'Analyser'}
                        </Bouton>
                    </form>
                </Carte>
                {apercu && <Apercu key={apercu.jeton} apercu={apercu} />}
                {bilan && !apercu && <Bilan bilan={bilan} />}
            </div>
        </AppLayout>
    );
}
