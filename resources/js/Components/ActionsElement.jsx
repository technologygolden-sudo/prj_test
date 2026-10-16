import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Bouton, Carte, Champ, Liste, Pastille, Saisie, Tableau, dateFr, usePeut } from './ui';

const typesDeplacement = { affectation: 'Rangement', deplacement: 'Déplacement', envoi: 'Envoi (transfert)', reception: 'Réception (transfert)', cession: 'Cession' };
const typesDeclaration = { defaut: 'Défaut (SAV)', reparation: 'Réparation (Lab)', reconditionnement: 'Reconditionnement (Lab)' };

export function EtatPastille({ etat }) {
    return etat === 'defectueux' ? <Pastille couleur="rouge">Défectueux</Pastille> : <Pastille couleur="vert">Bon état</Pastille>;
}

function Formulaire({ url, champs, valeursInitiales, bouton, variante = 'primaire', onFait }) {
    const { data, setData, post, processing, errors, reset } = useForm(valeursInitiales);
    return (
        <form
            className="flex flex-wrap items-end gap-3"
            onSubmit={(e) => {
                e.preventDefault();
                post(url, { preserveScroll: true, onSuccess: () => { reset(); onFait?.(); } });
            }}
        >
            {champs(data, setData, errors)}
            <Bouton type="submit" variante={variante} disabled={processing}>
                {bouton}
            </Bouton>
            {errors.cible && <p className="w-full text-sm text-red-700">{errors.cible}</p>}
        </form>
    );
}

/**
 * Actions d'emplacement et d'état d'un article ou d'un kit : déplacement interne motivé, cession GREENO/TEVIA,
 * défaut (SAV), réparation ou reconditionnement (Lab), et historique.
 */
export default function ActionsElement({ type, element, emplacement, emplacements, historique, disponible }) {
    const peut = usePeut();
    const [onglet, setOnglet] = useState(null);
    const memeSite = emplacements.filter((e) => emplacement && e.site_id === emplacement.site_id && e.id !== emplacement.id);
    const pourDeplacement = memeSite.filter((e) => !e.espace || e.espace === element.proprietaire).filter((e) => element.etat !== 'defectueux' || !e.vente);
    const pourCession = memeSite.filter((e) => e.espace && e.espace !== element.proprietaire).filter((e) => element.etat !== 'defectueux' || !e.vente);
    const pourDefaut = emplacements.filter((e) => emplacement && e.site_id === emplacement.site_id && !e.vente);
    const lot = type === 'unite' && element.nature === 'lot';
    const base = { type, id: element.id };

    const actions = [
        peut('stock.deplacer') && { cle: 'deplacer', libelle: 'Déplacer' },
        peut('stock.ceder') && pourCession.length > 0 && { cle: 'ceder', libelle: `Céder à ${element.proprietaire === 'GREENO' ? 'TEVIA' : 'GREENO'}` },
        peut('sav.declarer') && element.etat === 'bon' && { cle: 'defaut', libelle: 'Déclarer un défaut' },
        peut('lab.reparer') && emplacement?.type === 'lab' && { cle: 'reparation', libelle: element.etat === 'defectueux' ? 'Déclarer la réparation' : 'Déclarer un reconditionnement' },
    ].filter(Boolean);

    return (
        <>
            <Carte titre="Emplacement et état" actions={<EtatPastille etat={element.etat} />}>
                <dl className="grid gap-4 text-sm sm:grid-cols-3">
                    <div>
                        <dt className="text-slate-500">Emplacement</dt>
                        <dd className="font-medium">{emplacement?.libelle ?? (element.statut === 'en_transit' ? 'En transit' : '—')}</dd>
                    </div>
                    <div>
                        <dt className="text-slate-500">Propriétaire</dt>
                        <dd className="font-medium">{element.proprietaire}</dd>
                    </div>
                    <div>
                        <dt className="text-slate-500">Vente</dt>
                        <dd className="font-medium">{emplacement?.vente ? 'Dans un shop : peut être remis à un technicien' : 'Hors shop'}</dd>
                    </div>
                </dl>

                {disponible && emplacement && actions.length > 0 && (
                    <div className="mt-4 border-t border-slate-100 pt-4">
                        <div className="mb-3 flex flex-wrap gap-2">
                            {actions.map((a) => (
                                <Bouton key={a.cle} variante={onglet === a.cle ? 'primaire' : 'secondaire'} onClick={() => setOnglet(onglet === a.cle ? null : a.cle)}>
                                    {a.libelle}
                                </Bouton>
                            ))}
                        </div>

                        {onglet === 'deplacer' && (
                            <Formulaire
                                url="/emplacements/deplacer"
                                valeursInitiales={{ ...base, emplacement_id: '', motif: '', quantite: lot ? element.quantite_restante : '' }}
                                bouton="Déplacer"
                                onFait={() => setOnglet(null)}
                                champs={(data, setData, errors) => (
                                    <>
                                        <Champ libelle="Vers" erreur={errors.emplacement_id} className="w-72">
                                            <Liste value={data.emplacement_id} onChange={(e) => setData('emplacement_id', e.target.value)}>
                                                <option value="">Choisir…</option>
                                                {pourDeplacement.map((e) => (
                                                    <option key={e.id} value={e.id}>
                                                        {e.nom}
                                                    </option>
                                                ))}
                                            </Liste>
                                        </Champ>
                                        {lot && (
                                            <Champ libelle={`Quantité (sur ${element.quantite_restante})`} erreur={errors.quantite} className="w-40">
                                                <Saisie type="number" min={1} max={element.quantite_restante} value={data.quantite} onChange={(e) => setData('quantite', e.target.value)} />
                                            </Champ>
                                        )}
                                        <Champ libelle="Motif" erreur={errors.motif} className="min-w-64 flex-1">
                                            <Saisie value={data.motif} onChange={(e) => setData('motif', e.target.value)} placeholder="Mise à disposition QA/QC, réassort du shop…" />
                                        </Champ>
                                    </>
                                )}
                            />
                        )}
                        {onglet === 'deplacer' && <p className="mt-2 text-xs text-slate-500">Pour un autre site, passez par un transfert. Une partie d’un lot crée un nouveau lot, avec sa propre étiquette.</p>}

                        {onglet === 'ceder' && (
                            <Formulaire
                                url="/emplacements/ceder"
                                valeursInitiales={{ ...base, emplacement_id: '', motif: '' }}
                                bouton="Céder"
                                onFait={() => setOnglet(null)}
                                champs={(data, setData, errors) => (
                                    <>
                                        <Champ libelle="Vers" erreur={errors.emplacement_id} className="w-72">
                                            <Liste value={data.emplacement_id} onChange={(e) => setData('emplacement_id', e.target.value)}>
                                                <option value="">Choisir…</option>
                                                {pourCession.map((e) => (
                                                    <option key={e.id} value={e.id}>
                                                        {e.nom}
                                                    </option>
                                                ))}
                                            </Liste>
                                        </Champ>
                                        <Champ libelle="Motif de la cession" erreur={errors.motif} className="min-w-64 flex-1">
                                            <Saisie value={data.motif} onChange={(e) => setData('motif', e.target.value)} />
                                        </Champ>
                                    </>
                                )}
                            />
                        )}

                        {onglet === 'defaut' && (
                            <Formulaire
                                url="/etat/defaut"
                                variante="danger"
                                valeursInitiales={{ ...base, emplacement_id: emplacement.vente ? '' : emplacement.id, description: '' }}
                                bouton="Déclarer défectueux"
                                onFait={() => setOnglet(null)}
                                champs={(data, setData, errors) => (
                                    <>
                                        <Champ libelle="Ranger dans" erreur={errors.emplacement_id} className="w-72">
                                            <Liste value={data.emplacement_id} onChange={(e) => setData('emplacement_id', e.target.value)}>
                                                <option value="">Choisir…</option>
                                                {pourDefaut.map((e) => (
                                                    <option key={e.id} value={e.id}>
                                                        {e.nom}
                                                    </option>
                                                ))}
                                            </Liste>
                                        </Champ>
                                        <Champ libelle="Description du défaut" erreur={errors.description} className="min-w-64 flex-1">
                                            <Saisie value={data.description} onChange={(e) => setData('description', e.target.value)} placeholder="Ne charge plus, écran fissuré…" />
                                        </Champ>
                                    </>
                                )}
                            />
                        )}

                        {onglet === 'reparation' && (
                            <Formulaire
                                url="/etat/reparation"
                                valeursInitiales={{ ...base, nature: element.etat === 'defectueux' ? 'reparation' : 'reconditionnement', description: '' }}
                                bouton="Enregistrer"
                                onFait={() => setOnglet(null)}
                                champs={(data, setData, errors) => (
                                    <>
                                        <Champ libelle="Nature" erreur={errors.nature} className="w-56">
                                            <Liste value={data.nature} onChange={(e) => setData('nature', e.target.value)}>
                                                {element.etat === 'defectueux' && <option value="reparation">Réparation</option>}
                                                <option value="reconditionnement">Reconditionnement</option>
                                            </Liste>
                                        </Champ>
                                        <Champ libelle="Compte rendu" erreur={errors.description} className="min-w-64 flex-1">
                                            <Saisie value={data.description} onChange={(e) => setData('description', e.target.value)} placeholder="Batterie remplacée, test de charge OK…" />
                                        </Champ>
                                    </>
                                )}
                            />
                        )}
                    </div>
                )}
            </Carte>

            <Carte titre="Historique des emplacements">
                <Tableau colonnes={[{ libelle: 'Date' }, { libelle: 'Mouvement' }, { libelle: 'De' }, { libelle: 'Vers' }, { libelle: 'Motif' }, { libelle: 'Par' }]} vide={historique.deplacements.length === 0 ? 'Aucun mouvement.' : null}>
                    {historique.deplacements.map((m) => (
                        <tr key={m.id}>
                            <td className="px-5 py-2 whitespace-nowrap">{dateFr(m.horodatage)}</td>
                            <td className="px-5 py-2">
                                {typesDeplacement[m.type] ?? m.type}
                                {m.transfert_id && (
                                    <a href={`/transferts/${m.transfert_id}`} className="ml-1 text-green-800 hover:underline">
                                        n° {m.transfert_id}
                                    </a>
                                )}
                            </td>
                            <td className="px-5 py-2 text-slate-600">{m.de || '—'}</td>
                            <td className="px-5 py-2 text-slate-600">{m.vers || '—'}</td>
                            <td className="px-5 py-2 text-slate-600">{m.motif ?? '—'}</td>
                            <td className="px-5 py-2 text-slate-600">{m.utilisateur}</td>
                        </tr>
                    ))}
                </Tableau>
            </Carte>

            {historique.declarations.length > 0 && (
                <Carte titre="SAV et Lab">
                    <Tableau colonnes={[{ libelle: 'Date' }, { libelle: 'Déclaration' }, { libelle: 'Détail' }, { libelle: 'Par' }]}>
                        {historique.declarations.map((d) => (
                            <tr key={d.id}>
                                <td className="px-5 py-2 whitespace-nowrap">{dateFr(d.horodatage)}</td>
                                <td className="px-5 py-2">{typesDeclaration[d.type]}</td>
                                <td className="px-5 py-2 text-slate-600">{d.description}</td>
                                <td className="px-5 py-2 text-slate-600">{d.utilisateur}</td>
                            </tr>
                        ))}
                    </Tableau>
                </Carte>
            )}
        </>
    );
}
