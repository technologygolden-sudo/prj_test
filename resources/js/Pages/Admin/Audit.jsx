import { router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import { Bouton, Carte, Champ, Liste, Pagination, Saisie, Tableau, dateFr } from '../../Components/ui';

function Valeur({ json }) {
    if (!json) {
        return <span className="text-slate-400">—</span>;
    }
    const texte = typeof json === 'string' ? json : JSON.stringify(json);
    return (
        <details>
            <summary className="cursor-pointer text-green-800">Voir</summary>
            <pre className="mt-1 max-w-md overflow-x-auto rounded bg-slate-50 p-2 text-xs whitespace-pre-wrap">{texte}</pre>
        </details>
    );
}

export default function Audit({ lignes, filtres, utilisateurs, actions }) {
    const [f, setF] = useState({ utilisateur: filtres.utilisateur ?? '', action: filtres.action ?? '', du: filtres.du ?? '', au: filtres.au ?? '' });
    const actifs = Object.fromEntries(Object.entries(f).filter(([, v]) => v));
    const export_ = `/admin/audit/export?${new URLSearchParams(actifs)}`;

    return (
        <AppLayout titre="Journal d’audit" actions={<Bouton variante="secondaire" href={export_} externe>Exporter (CSV)</Bouton>}>
            <Carte>
                <form
                    className="mb-4 flex flex-wrap items-end gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get('/admin/audit', actifs, { preserveState: true, replace: true });
                    }}
                >
                    <Champ libelle="Utilisateur" className="w-44">
                        <Liste value={f.utilisateur} onChange={(e) => setF({ ...f, utilisateur: e.target.value })}>
                            <option value="">Tous</option>
                            {utilisateurs.map((u) => (
                                <option key={u.id} value={u.id}>
                                    {u.login}
                                </option>
                            ))}
                        </Liste>
                    </Champ>
                    <Champ libelle="Action" className="w-44">
                        <Liste value={f.action} onChange={(e) => setF({ ...f, action: e.target.value })}>
                            <option value="">Toutes</option>
                            {actions.map((a) => (
                                <option key={a} value={a}>
                                    {a}
                                </option>
                            ))}
                        </Liste>
                    </Champ>
                    <Champ libelle="Du" className="w-40">
                        <Saisie type="date" value={f.du} onChange={(e) => setF({ ...f, du: e.target.value })} />
                    </Champ>
                    <Champ libelle="Au" className="w-40">
                        <Saisie type="date" value={f.au} onChange={(e) => setF({ ...f, au: e.target.value })} />
                    </Champ>
                    <Bouton type="submit" variante="secondaire">
                        Filtrer
                    </Bouton>
                </form>
                <Tableau
                    colonnes={[{ libelle: 'Date' }, { libelle: 'Utilisateur' }, { libelle: 'IP' }, { libelle: 'Action' }, { libelle: 'Objet' }, { libelle: 'Avant' }, { libelle: 'Après' }]}
                    vide={lignes.data.length === 0 ? 'Aucune entrée.' : null}
                >
                    {lignes.data.map((l) => (
                        <tr key={l.id} className="align-top">
                            <td className="px-5 py-2 whitespace-nowrap">{dateFr(l.horodatage)}</td>
                            <td className="px-5 py-2">{l.login ?? '—'}</td>
                            <td className="px-5 py-2 font-mono text-xs">{l.ip ?? '—'}</td>
                            <td className="px-5 py-2">{l.action}</td>
                            <td className="px-5 py-2">
                                {l.objet_type}
                                {l.objet_id && <span className="text-slate-500"> #{l.objet_id}</span>}
                            </td>
                            <td className="px-5 py-2">
                                <Valeur json={l.valeur_avant} />
                            </td>
                            <td className="px-5 py-2">
                                <Valeur json={l.valeur_apres} />
                            </td>
                        </tr>
                    ))}
                </Tableau>
                <p className="mt-3 text-xs text-slate-500">Journal en ajout seul : aucune entrée ne peut être modifiée ni supprimée (ENF05).</p>
                <Pagination liens={lignes.links} />
            </Carte>
        </AppLayout>
    );
}
