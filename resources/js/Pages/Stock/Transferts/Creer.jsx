import { useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '../../../Layouts/AppLayout';
import { postJson } from '../../../Components/http';
import { signal } from '../../../Components/signal';
import { EtatPastille } from '../../../Components/ActionsElement';
import { Bouton, Carte, Champ, Liste, Pastille, Saisie, Tableau, classes } from '../../../Components/ui';

/**
 * Transfert en deux étapes (RG34) :
 *  1. le bon : articles et/ou kits avec leurs quantités, parmi le stock disponible au site de départ ;
 *  2. le scan : chaque élément est rattaché à sa ligne, qui se verrouille une fois la quantité atteinte.
 */
export default function Creer({ sites, siteImpose }) {
    const entrepot = sites.find((s) => s.type === 'entrepot');
    const { data, setData, post, transform, processing, errors } = useForm({
        site_origine_id: siteImpose ?? entrepot?.id ?? '',
        site_destination_id: '',
        motif: '',
        prevu: [], // [{ cle: 'article:12' | 'kit:3', quantite }]
        lignes: [], // éléments scannés
    });
    const [etape, setEtape] = useState(1);
    const [dispo, setDispo] = useState({ articles: [], kits: [] });
    const [ajout, setAjout] = useState({ cle: '', quantite: 1 });
    const [code, setCode] = useState('');
    const [message, setMessage] = useState(null);
    const champScan = useRef(null);

    useEffect(() => {
        if (!data.site_origine_id) return;
        fetch(`/transferts/disponibilites?site_id=${data.site_origine_id}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => r.json())
            .then((r) => setDispo({ articles: r.articles ?? [], kits: r.kits ?? [] }));
    }, [data.site_origine_id]);

    const options = [
        ...dispo.kits.map((k) => ({ cle: `kit:${k.id}`, type: 'kit', libelle: `Kit ${k.reference} · ${k.nom}`, disponible: k.disponible })),
        ...dispo.articles.map((a) => ({ cle: `article:${a.id}`, type: 'article', libelle: `${a.reference} · ${a.nom}`, disponible: a.disponible, serialise: a.est_serialise })),
    ];
    const option = (cle) => options.find((o) => o.cle === cle);
    const scanne = (cle) => data.lignes.filter((l) => l.cle === cle).reduce((s, l) => s + Number(l.quantite), 0);
    const toutComplet = data.prevu.length > 0 && data.prevu.every((p) => scanne(p.cle) === Number(p.quantite));

    const ajouterLigne = () => {
        const o = option(ajout.cle);
        const quantite = Number(ajout.quantite);
        if (!o || quantite < 1) return;
        if (quantite > o.disponible) {
            setMessage({ alerte: true, texte: `Seulement ${o.disponible} disponible(s) pour ${o.libelle}.` });
            return;
        }
        setData('prevu', [...data.prevu.filter((p) => p.cle !== o.cle), { cle: o.cle, quantite }]);
        setAjout({ cle: '', quantite: 1 });
        setMessage(null);
    };

    const scanner = async () => {
        const valeur = code.trim();
        setCode('');
        if (!valeur) return;
        try {
            const r = await postJson('/transferts/verifier', { code: valeur, site_id: Number(data.site_origine_id) });
            const ligne = data.prevu.find((p) => p.cle === r.cle);
            if (!ligne) {
                signal.erreur();
                setMessage({ alerte: true, texte: `${r.libelle} n’est pas dans le bon de transfert.` });
                return;
            }
            if (data.lignes.some((l) => l.type === r.type && l.id === r.id)) {
                signal.erreur();
                setMessage({ alerte: true, texte: `${r.libelle} est déjà scanné.` });
                return;
            }
            const reste = Number(ligne.quantite) - scanne(r.cle);
            if (reste <= 0) {
                signal.erreur();
                setMessage({ alerte: true, texte: `Quantité déjà atteinte pour ${option(r.cle)?.libelle ?? r.libelle} : ligne verrouillée.` });
                return;
            }
            const quantite = r.lot ? Math.min(reste, r.max) : 1;
            const lignes = [...data.lignes, { ...r, quantite }];
            setData('lignes', lignes);
            const total = lignes.filter((l) => l.cle === r.cle).reduce((s, l) => s + Number(l.quantite), 0);
            const fini = data.prevu.every((p) => (p.cle === r.cle ? total : lignes.filter((l) => l.cle === p.cle).reduce((s, l) => s + Number(l.quantite), 0)) === Number(p.quantite));
            if (fini) {
                signal.complet();
                setMessage({ alerte: false, texte: 'Toutes les quantités du bon sont atteintes : vous pouvez envoyer le transfert.' });
            } else if (total === Number(ligne.quantite)) {
                signal.complet();
                setMessage({ alerte: false, texte: `${option(r.cle)?.libelle} : quantité atteinte, ligne verrouillée.` });
            } else {
                signal.ok();
                setMessage({ alerte: false, texte: `${r.libelle} ajouté${r.lot ? ` (${quantite} pris)` : ''}.` });
            }
        } catch (e) {
            signal.erreur();
            setMessage({ alerte: true, texte: e.message });
        }
        champScan.current?.focus();
    };

    const envoyer = (e) => {
        e.preventDefault();
        transform((d) => ({ ...d, lignes: d.lignes.map((l) => ({ type: l.type, id: l.id, quantite: l.quantite })) }));
        post('/transferts');
    };

    return (
        <AppLayout titre="Nouveau transfert">
            <form className="space-y-6" onSubmit={envoyer}>
                <Carte titre="Bon de transfert">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Champ libelle="Site de départ" erreur={errors.site_origine_id}>
                            <Liste
                                value={data.site_origine_id}
                                disabled={Boolean(siteImpose) || etape === 2}
                                onChange={(e) => setData((d) => ({ ...d, site_origine_id: e.target.value, prevu: [], lignes: [] }))}
                            >
                                <option value="">Choisir…</option>
                                {sites.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.nom}
                                    </option>
                                ))}
                            </Liste>
                        </Champ>
                        <Champ libelle="Site d’arrivée" erreur={errors.site_destination_id}>
                            <Liste value={data.site_destination_id} onChange={(e) => setData('site_destination_id', e.target.value)}>
                                <option value="">Choisir…</option>
                                {sites
                                    .filter((s) => String(s.id) !== String(data.site_origine_id))
                                    .map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.nom}
                                        </option>
                                    ))}
                            </Liste>
                        </Champ>
                        <Champ libelle="Motif" erreur={errors.motif}>
                            <Saisie value={data.motif} onChange={(e) => setData('motif', e.target.value)} placeholder="Réassort du shop, retour SAV vers le Lab…" />
                        </Champ>
                    </div>
                </Carte>

                <Carte
                    titre="1. Quantités à transférer"
                    actions={etape === 2 && <Bouton variante="secondaire" onClick={() => { setEtape(1); setData('lignes', []); }}>Modifier le bon</Bouton>}
                >
                    {etape === 1 && (
                        <div className="mb-4 flex flex-wrap items-end gap-3">
                            <Champ libelle="Kit ou article (stock disponible au départ)" className="min-w-80 flex-1">
                                <Liste value={ajout.cle} onChange={(e) => setAjout({ ...ajout, cle: e.target.value })}>
                                    <option value="">Choisir…</option>
                                    {dispo.kits.length > 0 && (
                                        <optgroup label="Kits montés">
                                            {options.filter((o) => o.type === 'kit').map((o) => (
                                                <option key={o.cle} value={o.cle}>
                                                    {o.libelle} — {o.disponible} dispo
                                                </option>
                                            ))}
                                        </optgroup>
                                    )}
                                    <optgroup label="Articles">
                                        {options.filter((o) => o.type === 'article').map((o) => (
                                            <option key={o.cle} value={o.cle}>
                                                {o.libelle} — {o.disponible} dispo
                                            </option>
                                        ))}
                                    </optgroup>
                                </Liste>
                            </Champ>
                            <Champ libelle="Quantité" className="w-28">
                                <Saisie type="number" min={1} value={ajout.quantite} onChange={(e) => setAjout({ ...ajout, quantite: e.target.value })} />
                            </Champ>
                            <Bouton variante="secondaire" onClick={ajouterLigne} disabled={!ajout.cle}>
                                Ajouter au bon
                            </Bouton>
                        </div>
                    )}
                    <Tableau
                        colonnes={[{ libelle: 'Type' }, { libelle: 'Élément' }, { libelle: 'Prévu', droite: true }, { libelle: 'Scanné', droite: true }, { libelle: '' }]}
                        vide={data.prevu.length === 0 ? 'Ajoutez les kits et articles à transférer, avec leur quantité.' : null}
                    >
                        {data.prevu.map((p) => {
                            const o = option(p.cle);
                            const n = scanne(p.cle);
                            const complet = n === Number(p.quantite);
                            return (
                                <tr key={p.cle} className={classes(complet && 'bg-green-50')}>
                                    <td className="px-5 py-2">{o?.type === 'kit' ? <Pastille couleur="vert">Kit</Pastille> : <Pastille>Article</Pastille>}</td>
                                    <td className="px-5 py-2">{o?.libelle ?? p.cle}</td>
                                    <td className="px-5 py-2 text-right tabular-nums">{p.quantite}</td>
                                    <td className="px-5 py-2 text-right tabular-nums">{etape === 2 ? n : '—'}</td>
                                    <td className="px-5 py-2 text-right">
                                        {etape === 1 ? (
                                            <Bouton variante="discret" onClick={() => setData('prevu', data.prevu.filter((x) => x.cle !== p.cle))}>
                                                Retirer
                                            </Bouton>
                                        ) : complet ? (
                                            <Pastille couleur="vert">Atteint · verrouillé</Pastille>
                                        ) : (
                                            <Pastille couleur="orange">Reste {Number(p.quantite) - n}</Pastille>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </Tableau>
                    {etape === 1 && (
                        <Bouton className="mt-4" disabled={data.prevu.length === 0} onClick={() => { setEtape(2); setMessage(null); setTimeout(() => champScan.current?.focus(), 50); }}>
                            Passer au scan
                        </Bouton>
                    )}
                </Carte>

                {etape === 2 && (
                    <Carte titre={`2. Scan (${data.lignes.length} élément(s))`}>
                        {toutComplet && (
                            <p role="status" className="mb-3 rounded-md bg-green-100 px-3 py-2 text-sm font-medium text-green-900 ring-1 ring-green-300">
                                Toutes les quantités sont atteintes : le transfert peut être envoyé.
                            </p>
                        )}
                        <div className="mb-3 flex max-w-lg gap-2">
                            <Saisie
                                ref={champScan}
                                value={code}
                                disabled={toutComplet}
                                onChange={(e) => setCode(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        scanner();
                                    }
                                }}
                                placeholder="Scanner le code-barres d’un kit monté ou d’un article"
                                aria-label="Scanner un kit ou un article"
                            />
                            <Bouton variante="secondaire" onClick={scanner} disabled={toutComplet}>
                                Ajouter
                            </Bouton>
                        </div>
                        {message && <p className={`mb-3 text-sm ${message.alerte ? 'text-amber-800' : 'text-green-800'}`}>{message.texte}</p>}
                        {(errors.lignes || errors.cibles || errors.prevu) && <p className="mb-3 text-sm text-red-700">{errors.lignes ?? errors.cibles ?? errors.prevu}</p>}
                        <Tableau colonnes={[{ libelle: 'Type' }, { libelle: 'Élément scanné' }, { libelle: 'Emplacement' }, { libelle: 'État' }, { libelle: 'Qté', droite: true }, { libelle: '' }]}>
                            {data.lignes.map((l, i) => (
                                <tr key={`${l.type}-${l.id}`}>
                                    <td className="px-5 py-2">{l.type === 'kit' ? <Pastille couleur="vert">Kit</Pastille> : <Pastille>Article</Pastille>}</td>
                                    <td className="px-5 py-2">
                                        {l.libelle} <Pastille>{l.proprietaire}</Pastille>
                                    </td>
                                    <td className="px-5 py-2 text-slate-600">{l.emplacement}</td>
                                    <td className="px-5 py-2">
                                        <EtatPastille etat={l.etat} />
                                    </td>
                                    <td className="px-5 py-2 text-right tabular-nums">{l.quantite}</td>
                                    <td className="px-5 py-2 text-right">
                                        <Bouton variante="discret" onClick={() => setData('lignes', data.lignes.filter((_, j) => j !== i))}>
                                            Retirer
                                        </Bouton>
                                    </td>
                                </tr>
                            ))}
                        </Tableau>
                        <p className="mt-3 text-xs text-slate-500">
                            Un kit monté a le même n° de série que son article porteur : le scan désigne alors le kit entier. Une partie d’un lot part dans un nouveau lot, avec sa propre étiquette.
                        </p>
                    </Carte>
                )}

                <div className="flex gap-2">
                    <Bouton type="submit" disabled={processing || etape !== 2 || !toutComplet || !data.site_destination_id}>
                        Envoyer le transfert
                    </Bouton>
                    <Bouton variante="secondaire" href="/transferts">
                        Annuler
                    </Bouton>
                </div>
            </form>
        </AppLayout>
    );
}
