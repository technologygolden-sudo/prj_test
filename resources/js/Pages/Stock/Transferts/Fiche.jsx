import { Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../Layouts/AppLayout';
import { EtatPastille } from '../../../Components/ActionsElement';
import { Bouton, Carte, Liste, Pastille, Saisie, Tableau, dateFr, usePeut } from '../../../Components/ui';
import { StatutTransfert } from './Index';

/** Emplacement proposé à l'arrivée : un défectueux ne va jamais en shop ; dans l'entrepôt, l'espace du propriétaire. */
function emplacementParDefaut(ligne, emplacements) {
    const possibles = emplacements.filter((e) => (!e.espace || e.espace === ligne.proprietaire) && (ligne.etat !== 'defectueux' || !e.vente));
    const prefere = ligne.etat === 'defectueux' ? ['retour', 'defectueux', 'sav'] : ['vente', 'reception', 'entrepot'];
    for (const type of prefere) {
        const e = possibles.find((x) => x.type === type);
        if (e) return e.id;
    }
    return possibles[0]?.id ?? '';
}

function Reception({ transfert, lignes, emplacements }) {
    const attente = lignes.filter((l) => !l.recu_le);
    const [scan, setScan] = useState('');
    const [message, setMessage] = useState(null);
    const { errors } = usePage().props; // erreurs de la validation (FormulaireValidation)
    const { data, setData } = useForm({
        arrivees: Object.fromEntries(attente.map((l) => [l.id, emplacementParDefaut(l, emplacements)])),
        coches: [],
    });

    const cocher = (id) => setData('coches', data.coches.includes(id) ? data.coches.filter((x) => x !== id) : [...data.coches, id]);
    const scanner = () => {
        const valeur = scan.trim().toUpperCase();
        setScan('');
        const ligne = attente.find((l) => l.uid === valeur || l.libelle.toUpperCase().includes(`S/N ${valeur}`));
        if (!ligne) {
            setMessage({ alerte: true, texte: 'Cet élément ne fait pas partie de ce transfert (ou est déjà reçu).' });
            return;
        }
        if (!data.coches.includes(ligne.id)) cocher(ligne.id);
        setMessage({ alerte: false, texte: `${ligne.libelle} : reçu.` });
    };

    return (
        <Carte titre={`Valider la réception à ${transfert.destination}`}>
            <div className="mb-3 flex max-w-lg gap-2">
                <Saisie
                    autoFocus
                    value={scan}
                    onChange={(e) => setScan(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            scanner();
                        }
                    }}
                    placeholder="Scanner chaque élément reçu"
                    aria-label="Scanner un élément reçu"
                />
                <Bouton variante="secondaire" onClick={() => setData('coches', attente.map((l) => l.id))}>
                    Tout cocher
                </Bouton>
            </div>
            {message && <p className={`mb-3 text-sm ${message.alerte ? 'text-amber-800' : 'text-green-800'}`}>{message.texte}</p>}
            {errors.lignes && <p className="mb-3 text-sm text-red-700">{errors.lignes}</p>}
            <Tableau colonnes={[{ libelle: 'Reçu' }, { libelle: 'Élément' }, { libelle: 'État' }, { libelle: 'Ranger dans' }]}>
                {attente.map((l) => {
                    const possibles = emplacements.filter((e) => (!e.espace || e.espace === l.proprietaire) && (l.etat !== 'defectueux' || !e.vente));
                    return (
                        <tr key={l.id} className={data.coches.includes(l.id) ? 'bg-green-50/60' : ''}>
                            <td className="px-5 py-2">
                                <input type="checkbox" checked={data.coches.includes(l.id)} onChange={() => cocher(l.id)} aria-label={`Reçu : ${l.libelle}`} />
                            </td>
                            <td className="px-5 py-2">
                                {l.kit_id ? <Pastille couleur="vert">Kit</Pastille> : <Pastille>Article</Pastille>} {l.libelle} <Pastille>{l.proprietaire}</Pastille>
                                <span className="block font-mono text-xs text-slate-500">{l.uid}</span>
                            </td>
                            <td className="px-5 py-2">
                                <EtatPastille etat={l.etat} />
                            </td>
                            <td className="px-5 py-2">
                                <div className="w-64">
                                    <Liste value={data.arrivees[l.id]} onChange={(e) => setData('arrivees', { ...data.arrivees, [l.id]: e.target.value })} aria-label="Emplacement d’arrivée">
                                        {possibles.map((e) => (
                                            <option key={e.id} value={e.id}>
                                                {e.nom}
                                            </option>
                                        ))}
                                    </Liste>
                                </div>
                            </td>
                        </tr>
                    );
                })}
            </Tableau>
            <div className="mt-4 flex flex-wrap items-center gap-3">
                <FormulaireValidation transfertId={transfert.id} arrivees={Object.fromEntries(data.coches.map((id) => [id, data.arrivees[id]]))} nombre={data.coches.length} total={attente.length} />
            </div>
        </Carte>
    );
}

function FormulaireValidation({ transfertId, arrivees, nombre, total }) {
    const { post, processing, transform } = useForm({});
    return (
        <>
            <Bouton
                disabled={processing || nombre === 0}
                onClick={() => {
                    transform(() => ({ arrivees }));
                    post(`/transferts/${transfertId}/recevoir`, { preserveScroll: true });
                }}
            >
                Valider la réception de {nombre} élément(s)
            </Bouton>
            {nombre > 0 && nombre < total && <span className="text-sm text-amber-800">Les {total - nombre} élément(s) non cochés resteront en transit.</span>}
        </>
    );
}

export default function Fiche({ transfert, lignes, emplacements, peutRecevoir }) {
    const peut = usePeut();
    const enAttente = lignes.some((l) => !l.recu_le);

    return (
        <AppLayout titre={`Transfert n° ${transfert.id}`} actions={<Bouton variante="secondaire" href="/transferts">Transferts</Bouton>}>
            <div className="space-y-6">
                <Carte actions={<StatutTransfert valeur={transfert.statut} />}>
                    <dl className="grid gap-4 text-sm sm:grid-cols-4">
                        {[
                            ['De', transfert.origine],
                            ['Vers', transfert.destination],
                            ['Envoyé', `${dateFr(transfert.envoye_le)} par ${transfert.envoyeur}`],
                            ['Dernière réception', transfert.recu_le ? `${dateFr(transfert.recu_le)} par ${transfert.receveur}` : '—'],
                        ].map(([l, v]) => (
                            <div key={l}>
                                <dt className="text-slate-500">{l}</dt>
                                <dd className="font-medium">{v}</dd>
                            </div>
                        ))}
                        <div className="sm:col-span-4">
                            <dt className="text-slate-500">Motif</dt>
                            <dd className="font-medium">{transfert.motif}</dd>
                        </div>
                    </dl>
                </Carte>

                {enAttente && peut('transferts.recevoir') && peutRecevoir && <Reception transfert={transfert} lignes={lignes} emplacements={emplacements} />}
                {enAttente && !peutRecevoir && (
                    <p className="rounded-md bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200">
                        En attente de la validation par {transfert.destination} : seul un utilisateur de ce site peut confirmer la réception.
                    </p>
                )}

                <Carte titre={`Éléments (${lignes.length})`}>
                    <Tableau colonnes={[{ libelle: 'Élément' }, { libelle: 'UID' }, { libelle: 'État' }, { libelle: 'Arrivée' }]}>
                        {lignes.map((l) => (
                            <tr key={l.id}>
                                <td className="px-5 py-2">
                                    {l.kit_id ? <Pastille couleur="vert">Kit</Pastille> : <Pastille>Article</Pastille>}{' '}
                                    <Link href={l.kit_id ? `/kits-assembles/${l.kit_id}` : `/stock/${l.unite_id}`} className="text-green-800 hover:underline">
                                        {l.libelle}
                                    </Link>
                                </td>
                                <td className="px-5 py-2 font-mono text-xs">{l.uid}</td>
                                <td className="px-5 py-2">
                                    <EtatPastille etat={l.etat} />
                                </td>
                                <td className="px-5 py-2">{l.recu_le ? `${l.arrivee} · ${dateFr(l.recu_le)}` : <Pastille couleur="orange">En transit</Pastille>}</td>
                            </tr>
                        ))}
                    </Tableau>
                </Carte>
            </div>
        </AppLayout>
    );
}
