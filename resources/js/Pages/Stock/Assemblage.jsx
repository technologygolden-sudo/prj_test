import { router, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import { postJson } from '../../Components/http';
import { signal } from '../../Components/signal';
import { Bouton, Carte, Pastille, Saisie, Tableau, usePeut } from '../../Components/ui';

/** Assemblage guidé : chaque scan est contrôlé aussitôt par le serveur (processus 4.2). */
function Scan({ nomenclature }) {
    const { data, setData, post, processing, errors } = useForm({ version_id: nomenclature.version_id, composants: [] });
    const [code, setCode] = useState('');
    const [message, setMessage] = useState(null);
    const champ = useRef(null);

    const pris = (articleId) => data.composants.filter((c) => c.article_id === articleId).reduce((s, c) => s + Number(c.quantite), 0);
    // Seuls les articles sérialisés doivent être scannés ; les non sérialisés manquants sont prélevés automatiquement.
    const complet = nomenclature.lignes.every((l) => (l.est_serialise ? pris(l.article_id) === l.quantite : pris(l.article_id) <= l.quantite));
    const estPorteur = (articleId) => Number(articleId) === Number(nomenclature.porteur_id);
    const lignePorteur = nomenclature.lignes.find((l) => estPorteur(l.article_id));
    const porteurScanne = data.composants.find((c) => estPorteur(c.article_id));

    const scanner = async () => {
        const valeur = code.trim();
        setCode('');
        if (!valeur) {
            return;
        }
        try {
            const u = await postJson('/assemblage/verifier', { version_id: nomenclature.version_id, code: valeur });
            const ligne = nomenclature.lignes.find((l) => l.article_id === u.article_id);
            const reste = ligne.quantite - pris(u.article_id);
            if (data.composants.some((c) => c.unite_id === u.unite_id)) {
                signal.erreur();
                setMessage({ alerte: true, texte: 'Cette unité est déjà scannée.' });
            } else if (reste <= 0) {
                signal.erreur();
                setMessage({ alerte: true, texte: `${ligne.reference} : quantité déjà atteinte (${ligne.quantite}).` });
            } else {
                const quantite = u.nature === 'serie' ? 1 : Math.min(reste, u.disponible);
                const composants = [...data.composants, { ...u, quantite, reference: ligne.reference }];
                setData('composants', composants);
                const prisApres = (id) => composants.filter((c) => c.article_id === id).reduce((s, c) => s + Number(c.quantite), 0);
                const serialisesComplets = nomenclature.lignes.filter((l) => l.est_serialise).every((l) => prisApres(l.article_id) === l.quantite);
                if (serialisesComplets) {
                    signal.complet();
                    setMessage({ alerte: false, texte: 'Tous les articles sérialisés du kit sont scannés : vous pouvez valider le montage.' });
                } else {
                    signal.ok();
                    setMessage({ alerte: false, texte: `${ligne.reference} ${u.numero_serie ? `S/N ${u.numero_serie}` : `lot (${quantite} pris)`} ajouté.` });
                }
            }
        } catch (e) {
            signal.erreur();
            setMessage({ alerte: true, texte: e.message });
        }
        champ.current?.focus();
    };

    return (
        <form
            className="space-y-6"
            onSubmit={(e) => {
                e.preventDefault();
                post('/assemblage');
            }}
        >
            {lignePorteur && (
                <div className={`rounded-lg p-4 ring-1 ${porteurScanne ? 'bg-green-50 ring-green-200' : 'bg-amber-50 ring-amber-200'}`}>
                    <p className="text-sm text-slate-600">Article porteur de série : il donne son n° de série au kit (RG06)</p>
                    <p className="mt-1 text-lg font-semibold text-slate-900">
                        {lignePorteur.reference} · {lignePorteur.nom}
                    </p>
                    {porteurScanne ? (
                        <p className="mt-1 text-sm text-green-900">
                            Scanné : le kit portera le n° de série <span className="font-mono font-semibold">{porteurScanne.numero_serie}</span>
                        </p>
                    ) : (
                        <p className="mt-1 text-sm text-amber-900">Scannez-le en premier.</p>
                    )}
                </div>
            )}

            <Carte titre="Nomenclature">
                <Tableau colonnes={[{ libelle: 'Article' }, { libelle: 'Désignation' }, { libelle: 'Scanné', droite: true }, { libelle: '' }]}>
                    {nomenclature.lignes.map((l) => {
                        const n = pris(l.article_id);
                        return (
                            <tr key={l.article_id} className={n === l.quantite ? 'bg-green-50/60' : ''}>
                                <td className="px-5 py-2 font-medium">{l.reference}</td>
                                <td className="px-5 py-2">
                                    {l.nom} {estPorteur(l.article_id) && <Pastille couleur="vert">Porteur de série</Pastille>}
                                </td>
                                <td className="px-5 py-2 text-right tabular-nums">
                                    {n} / {l.quantite}
                                </td>
                                <td className="px-5 py-2">
                                    {n === l.quantite ? (
                                        <Pastille couleur="vert">Complet</Pastille>
                                    ) : l.est_serialise ? (
                                        <Pastille couleur="orange">À scanner (n° de série)</Pastille>
                                    ) : (
                                        <Pastille>Prélevé automatiquement : {l.quantite - n}</Pastille>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </Tableau>
            </Carte>

            <Carte titre="Scan des articles sérialisés">
                <p className="mb-3 text-sm text-slate-600">
                    Scannez le code-barres (n° de série) de chaque article sérialisé. Les articles non sérialisés sont prélevés automatiquement dans les lots du
                    même site et du même propriétaire ; scannez un lot seulement pour imposer lequel utiliser.
                </p>
                <div className="mb-3 flex max-w-lg gap-2">
                    <Saisie
                        ref={champ}
                        autoFocus
                        value={code}
                        onChange={(e) => setCode(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                scanner();
                            }
                        }}
                        placeholder="Scanner le QR code ou le code-barres"
                        aria-label="Code du composant"
                    />
                    <Bouton variante="secondaire" onClick={scanner}>
                        Ajouter
                    </Bouton>
                </div>
                {message && <p className={`mb-3 text-sm ${message.alerte ? 'text-amber-800' : 'text-green-800'}`}>{message.texte}</p>}
                {errors.composants && <p className="mb-3 text-sm text-red-700">{errors.composants}</p>}
                {errors.version_id && <p className="mb-3 text-sm text-red-700">{errors.version_id}</p>}
                {data.composants.length > 0 && (
                    <ul className="divide-y divide-slate-100 text-sm">
                        {data.composants.map((c, i) => (
                            <li key={c.unite_id} className="flex items-center justify-between gap-3 py-1.5">
                                <span>
                                    <span className="font-medium">{c.reference}</span> {c.numero_serie ? `S/N ${c.numero_serie}` : `lot · ${c.quantite} pris sur ${c.disponible}`}
                                    <span className="ml-2 font-mono text-xs text-slate-500">{c.uid}</span>
                                </span>
                                <Bouton variante="discret" onClick={() => setData('composants', data.composants.filter((_, j) => j !== i))}>
                                    Retirer
                                </Bouton>
                            </li>
                        ))}
                    </ul>
                )}
            </Carte>

            <div className="flex gap-2">
                <Bouton type="submit" disabled={!complet || processing}>
                    Valider l’assemblage
                </Bouton>
                <Bouton variante="secondaire" onClick={() => router.get('/assemblage')}>
                    Changer de kit
                </Bouton>
            </div>
        </form>
    );
}

function ChoixKit({ types }) {
    const peut = usePeut();
    const [filtre, setFiltre] = useState('');
    const prets = types.filter((t) => t.version_id);
    const visibles = types.filter((t) => !filtre || t.libelle.toLowerCase().includes(filtre.toLowerCase()));

    return (
        <Carte titre="Type de kit à assembler" actions={<span className="text-sm text-slate-600">{prets.length} prêt(s) sur {types.length}</span>}>
            <Saisie type="search" autoFocus value={filtre} onChange={(e) => setFiltre(e.target.value)} placeholder="Rechercher un kit" className="mb-3 max-w-md" aria-label="Rechercher un kit" />
            <Tableau colonnes={[{ libelle: 'Type de kit' }, { libelle: '' }]} vide={visibles.length === 0 ? 'Aucun kit ne correspond.' : null}>
                {visibles.map((t) => (
                    <tr key={t.kit_id} className={t.version_id ? 'hover:bg-slate-50' : 'text-slate-500'}>
                        <td className="px-5 py-2">
                            {t.libelle}
                            {t.raison && (
                                <span className="ml-2">
                                    <Pastille couleur="orange">{t.raison}</Pastille>
                                </span>
                            )}
                        </td>
                        <td className="px-5 py-2 text-right whitespace-nowrap">
                            {t.version_id ? (
                                <Bouton onClick={() => router.get('/assemblage', { version: t.version_id })}>Assembler</Bouton>
                            ) : (
                                peut('articles.voir') && (
                                    <Bouton variante="discret" href={`/kits/${t.kit_id}`}>
                                        {peut('kits.gerer') ? 'Compléter la nomenclature' : 'Voir la fiche'}
                                    </Bouton>
                                )
                            )}
                        </td>
                    </tr>
                ))}
            </Tableau>
            <p className="mt-3 text-xs text-slate-500">
                On n’assemble que sur une nomenclature verrouillée : c’est elle qui désigne l’article porteur du n° de série du kit.
            </p>
        </Carte>
    );
}

export default function Assemblage({ types, nomenclature }) {
    return (
        <AppLayout titre="Assemblage d’un kit">
            {nomenclature ? <Scan key={nomenclature.version_id} nomenclature={nomenclature} /> : <ChoixKit types={types} />}
        </AppLayout>
    );
}
