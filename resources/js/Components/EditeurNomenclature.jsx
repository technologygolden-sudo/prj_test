import { Bouton, Liste, Pastille, Saisie } from './ui';

/**
 * Composition d'un kit : une seule liste d'articles (RG03). Parmi les articles sérialisés, un bouton radio
 * désigne le porteur du n° de série (RG05) : il est tagué et sa quantité est fixée à 1.
 * Données : porteurId (ou null) + lignes des autres articles.
 */
export default function EditeurNomenclature({ articles, porteurId, lignes, onChange, erreurs = {} }) {
    const porteur = porteurId ? Number(porteurId) : null;
    const lignesAffichees = [...(porteur ? [{ article_id: porteur, quantite: 1, porteur: true }] : []), ...lignes.map((l, i) => ({ ...l, index: i }))];
    const pris = new Set(lignesAffichees.map((l) => Number(l.article_id)).filter(Boolean));
    const article = (id) => articles.find((a) => Number(a.id) === Number(id));

    const choisirPorteur = (id) => {
        const reste = lignes.filter((l) => Number(l.article_id) !== Number(id));
        const ancien = porteur && porteur !== Number(id) ? [{ article_id: porteur, quantite: 1 }] : [];
        onChange({ porteurId: Number(id), lignes: [...ancien, ...reste] });
    };
    const retirerPorteur = () => onChange({ porteurId: null, lignes: [{ article_id: porteur, quantite: 1 }, ...lignes] });
    const changerLigne = (i, champ, valeur) => onChange({ porteurId, lignes: lignes.map((l, j) => (j === i ? { ...l, [champ]: valeur } : l)) });
    const erreurLigne = (i) => erreurs[`lignes.${i}.article_id`] ?? erreurs[`lignes.${i}.quantite`];

    return (
        <div className="space-y-3">
            {!porteur && (
                <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200">
                    Cochez « Porteur » sur l’article sérialisé qui donnera son n° de série au kit (obligatoire pour verrouiller).
                </p>
            )}
            {erreurs.porteur_id && <p className="text-sm text-red-700">{erreurs.porteur_id}</p>}

            <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                    <thead>
                        <tr className="text-left text-slate-600">
                            <th className="py-1.5 pr-3 font-medium">Porteur</th>
                            <th className="py-1.5 pr-3 font-medium">Article</th>
                            <th className="py-1.5 pr-3 font-medium">Quantité</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {lignesAffichees.map((l) => {
                            const a = article(l.article_id);
                            return (
                                <tr key={l.porteur ? 'porteur' : `l${l.index}`} className={l.porteur ? 'bg-green-50' : ''}>
                                    <td className="py-1.5 pr-3">
                                        <input
                                            type="radio"
                                            name="porteur"
                                            checked={Boolean(l.porteur)}
                                            disabled={!a?.est_serialise}
                                            title={a?.est_serialise ? 'Porteur du n° de série du kit' : 'Seul un article sérialisé peut porter le n° de série'}
                                            onChange={() => choisirPorteur(l.article_id)}
                                            aria-label={`Porteur : ${a?.reference ?? 'article'}`}
                                        />
                                    </td>
                                    <td className="py-1.5 pr-3">
                                        {l.porteur ? (
                                            <span className="flex flex-wrap items-center gap-2">
                                                <span className="font-medium">{a?.reference}</span> {a?.nom} <Pastille couleur="vert">Porteur de série</Pastille>
                                            </span>
                                        ) : (
                                            <div className="max-w-md min-w-64">
                                                <Liste value={l.article_id} onChange={(e) => changerLigne(l.index, 'article_id', e.target.value)} invalide={erreurLigne(l.index)} aria-label={`Article de la ligne ${l.index + 1}`}>
                                                    <option value="">Choisir…</option>
                                                    {articles
                                                        .filter((x) => Number(x.id) === Number(l.article_id) || !pris.has(Number(x.id)))
                                                        .map((x) => (
                                                            <option key={x.id} value={x.id}>
                                                                {x.reference} — {x.nom}
                                                                {x.est_serialise ? ' (sérialisé)' : ''}
                                                            </option>
                                                        ))}
                                                </Liste>
                                                {erreurLigne(l.index) && <p className="mt-1 text-sm text-red-700">{erreurLigne(l.index)}</p>}
                                            </div>
                                        )}
                                    </td>
                                    <td className="py-1.5 pr-3">
                                        {l.porteur ? (
                                            <span className="px-3 tabular-nums">1</span>
                                        ) : (
                                            <div className="w-24">
                                                <Saisie type="number" min={1} value={l.quantite} onChange={(e) => changerLigne(l.index, 'quantite', e.target.value)} aria-label={`Quantité de la ligne ${l.index + 1}`} />
                                            </div>
                                        )}
                                    </td>
                                    <td className="py-1.5 text-right whitespace-nowrap">
                                        {l.porteur ? (
                                            <Bouton variante="discret" onClick={retirerPorteur}>
                                                Ne plus porter
                                            </Bouton>
                                        ) : (
                                            <Bouton variante="discret" onClick={() => onChange({ porteurId, lignes: lignes.filter((_, j) => j !== l.index) })}>
                                                Retirer
                                            </Bouton>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            <Bouton variante="secondaire" onClick={() => onChange({ porteurId, lignes: [...lignes, { article_id: '', quantite: 1 }] })}>
                Ajouter un article
            </Bouton>
        </div>
    );
}
