import { router, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import { postJson } from '../../Components/http';
import { Bouton, Carte, Champ, Liste, Pastille, Saisie, Tableau } from '../../Components/ui';

/** Assemblage guidé : chaque scan est contrôlé aussitôt par le serveur (processus 4.2). */
function Scan({ nomenclature }) {
    const { data, setData, post, processing, errors } = useForm({ version_id: nomenclature.version_id, composants: [] });
    const [code, setCode] = useState('');
    const [message, setMessage] = useState(null);
    const champ = useRef(null);

    const pris = (articleId) => data.composants.filter((c) => c.article_id === articleId).reduce((s, c) => s + Number(c.quantite), 0);
    const complet = nomenclature.lignes.every((l) => pris(l.article_id) === l.quantite);

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
                setMessage({ alerte: true, texte: 'Cette unité est déjà scannée.' });
            } else if (reste <= 0) {
                setMessage({ alerte: true, texte: `${ligne.reference} : quantité déjà atteinte (${ligne.quantite}).` });
            } else {
                const quantite = u.nature === 'serie' ? 1 : Math.min(reste, u.disponible);
                setData('composants', [...data.composants, { ...u, quantite, reference: ligne.reference }]);
                setMessage({ alerte: false, texte: `${ligne.reference} ${u.numero_serie ? `S/N ${u.numero_serie}` : `lot (${quantite} pris)`} ajouté.` });
            }
        } catch (e) {
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
            <Carte titre="Nomenclature">
                <Tableau colonnes={[{ libelle: 'Article' }, { libelle: 'Désignation' }, { libelle: 'Scanné', droite: true }, { libelle: '' }]}>
                    {nomenclature.lignes.map((l) => {
                        const n = pris(l.article_id);
                        return (
                            <tr key={l.article_id} className={n === l.quantite ? 'bg-green-50/60' : ''}>
                                <td className="px-5 py-2 font-medium">{l.reference}</td>
                                <td className="px-5 py-2">
                                    {l.nom} {l.article_id === nomenclature.porteur_id && <Pastille couleur="vert">Porteur de série</Pastille>}
                                </td>
                                <td className="px-5 py-2 text-right tabular-nums">
                                    {n} / {l.quantite}
                                </td>
                                <td className="px-5 py-2">{n === l.quantite ? <Pastille couleur="vert">Complet</Pastille> : <Pastille couleur="orange">À scanner</Pastille>}</td>
                            </tr>
                        );
                    })}
                </Tableau>
            </Carte>

            <Carte titre="Scan des composants">
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

export default function Assemblage({ types, nomenclature }) {
    return (
        <AppLayout titre="Assemblage d’un kit">
            {nomenclature ? (
                <Scan key={nomenclature.version_id} nomenclature={nomenclature} />
            ) : (
                <Carte titre="Type de kit à assembler">
                    {types.length === 0 ? (
                        <p className="text-sm text-slate-600">Aucun type de kit n’a de version verrouillée : verrouillez une nomenclature d’abord.</p>
                    ) : (
                        <Champ libelle="Type de kit (version active)" className="max-w-lg">
                            <Liste defaultValue="" onChange={(e) => e.target.value && router.get('/assemblage', { version: e.target.value })}>
                                <option value="">Choisir…</option>
                                {types.map((t) => (
                                    <option key={t.version_id} value={t.version_id}>
                                        {t.libelle}
                                    </option>
                                ))}
                            </Liste>
                        </Champ>
                    )}
                </Carte>
            )}
        </AppLayout>
    );
}
