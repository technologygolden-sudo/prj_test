import { Bouton, Champ, Liste, Saisie } from './ui';

/**
 * Saisie d'une nomenclature : un porteur de série (article sérialisé, quantité 1 imposée, RG05)
 * et des lignes article + quantité (RG03).
 */
export default function EditeurNomenclature({ articles, porteurId, lignes, onChange, erreurs = {} }) {
    const serialises = articles.filter((a) => a.est_serialise);
    const pris = new Set([Number(porteurId), ...lignes.map((l) => Number(l.article_id))]);

    const changerLigne = (i, champ, valeur) => onChange({ porteurId, lignes: lignes.map((l, j) => (j === i ? { ...l, [champ]: valeur } : l)) });
    const erreurLigne = (i) => erreurs[`lignes.${i}.article_id`] ?? erreurs[`lignes.${i}.quantite`];

    return (
        <div className="space-y-4">
            <Champ libelle="Article porteur de série" htmlFor="porteur_id" erreur={erreurs.porteur_id} aide="Article sérialisé dont le kit reprendra le n° de série (quantité 1).">
                <Liste
                    id="porteur_id"
                    value={porteurId ?? ''}
                    onChange={(e) => onChange({ porteurId: e.target.value, lignes })}
                    invalide={erreurs.porteur_id}
                    className="max-w-lg"
                >
                    <option value="">Choisir…</option>
                    {serialises.map((a) => (
                        <option key={a.id} value={a.id}>
                            {a.reference} — {a.nom}
                        </option>
                    ))}
                </Liste>
            </Champ>

            <div>
                <p className="mb-2 text-sm font-medium text-slate-700">Autres composants</p>
                <div className="space-y-2">
                    {lignes.map((ligne, i) => (
                        <div key={i}>
                            <div className="flex items-center gap-2">
                                <div className="max-w-md min-w-0 flex-1">
                                    <Liste
                                        value={ligne.article_id}
                                        onChange={(e) => changerLigne(i, 'article_id', e.target.value)}
                                        aria-label={`Article de la ligne ${i + 1}`}
                                        invalide={erreurLigne(i)}
                                    >
                                        <option value="">Choisir…</option>
                                        {articles
                                            .filter((a) => Number(a.id) === Number(ligne.article_id) || !pris.has(a.id))
                                            .map((a) => (
                                                <option key={a.id} value={a.id}>
                                                    {a.reference} — {a.nom}
                                                </option>
                                            ))}
                                    </Liste>
                                </div>
                                <div className="w-24 shrink-0">
                                    <Saisie
                                        type="number"
                                        min={1}
                                        value={ligne.quantite}
                                        onChange={(e) => changerLigne(i, 'quantite', e.target.value)}
                                        aria-label={`Quantité de la ligne ${i + 1}`}
                                    />
                                </div>
                                <Bouton variante="discret" onClick={() => onChange({ porteurId, lignes: lignes.filter((_, j) => j !== i) })}>
                                    Retirer
                                </Bouton>
                            </div>
                            {erreurLigne(i) && <p className="mt-1 text-sm text-red-700">{erreurLigne(i)}</p>}
                        </div>
                    ))}
                </div>
                <Bouton variante="secondaire" className="mt-3" onClick={() => onChange({ porteurId, lignes: [...lignes, { article_id: '', quantite: 1 }] })}>
                    Ajouter un composant
                </Bouton>
            </div>
        </div>
    );
}
