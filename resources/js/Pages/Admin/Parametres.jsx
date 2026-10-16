import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import { Bouton, Carte, Champ, Liste, Pastille, Saisie, Tableau, dateFr } from '../../Components/ui';

function Societe({ societe }) {
    const { data, setData, put, processing, errors } = useForm({ nom_societe: societe?.nom_societe ?? '', num_sav: societe?.num_sav ?? '' });
    return (
        <form
            className="flex flex-wrap items-end gap-3"
            onSubmit={(e) => {
                e.preventDefault();
                put('/admin/parametres/societe', { preserveScroll: true });
            }}
        >
            <Champ libelle="Nom de la société" erreur={errors.nom_societe} className="w-64">
                <Saisie value={data.nom_societe} onChange={(e) => setData('nom_societe', e.target.value)} />
            </Champ>
            <Champ libelle="N° SAV" erreur={errors.num_sav} className="w-48">
                <Saisie value={data.num_sav} onChange={(e) => setData('num_sav', e.target.value)} />
            </Champ>
            <Bouton type="submit" disabled={processing}>
                Enregistrer
            </Bouton>
            {societe?.modifie_le && <p className="w-full text-xs text-slate-500">Dernière modification : {dateFr(societe.modifie_le)}. Imprimés sur chaque étiquette (RG15).</p>}
        </form>
    );
}

function FormImprimante({ imprimante, onFermer }) {
    const { data, setData, post, put, processing, errors } = useForm({
        nom: imprimante?.nom ?? '',
        adresse: imprimante?.adresse ?? 'tcp://',
        modele: imprimante?.modele ?? 'Zebra ZD411',
        dpi: String(imprimante?.dpi ?? 203),
        actif: imprimante?.actif ?? true,
    });
    const options = { preserveScroll: true, onSuccess: onFermer };

    return (
        <form
            className="grid gap-3 rounded-md bg-slate-50 p-4 sm:grid-cols-5"
            onSubmit={(e) => {
                e.preventDefault();
                imprimante ? put(`/admin/imprimantes/${imprimante.id}`, options) : post('/admin/imprimantes', options);
            }}
        >
            <Champ libelle="Nom" erreur={errors.nom}>
                <Saisie value={data.nom} onChange={(e) => setData('nom', e.target.value)} />
            </Champ>
            <Champ libelle="Adresse" erreur={errors.adresse} aide="tcp://IP:9100, fichier:nom ou navigateur" className="sm:col-span-2">
                <Saisie value={data.adresse} onChange={(e) => setData('adresse', e.target.value)} />
            </Champ>
            <Champ libelle="Modèle" erreur={errors.modele}>
                <Saisie value={data.modele} onChange={(e) => setData('modele', e.target.value)} />
            </Champ>
            <Champ libelle="Résolution" erreur={errors.dpi}>
                <Liste value={data.dpi} onChange={(e) => setData('dpi', e.target.value)}>
                    <option value="203">203 dpi</option>
                    <option value="300">300 dpi</option>
                    <option value="600">600 dpi</option>
                </Liste>
            </Champ>
            <label className="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" checked={data.actif} onChange={(e) => setData('actif', e.target.checked)} />
                Active
            </label>
            <div className="flex gap-2 sm:col-span-4">
                <Bouton type="submit" disabled={processing}>
                    Enregistrer
                </Bouton>
                <Bouton variante="secondaire" onClick={onFermer}>
                    Annuler
                </Bouton>
            </div>
        </form>
    );
}

function LigneModele({ modele }) {
    const { data, setData, put, processing, isDirty } = useForm({
        largeur_mm: modele.largeur_mm,
        hauteur_mm: modele.hauteur_mm,
        dpi: String(modele.dpi),
        actif: modele.actif,
    });
    return (
        <tr>
            <td className="px-5 py-2 font-mono text-xs">{modele.code}</td>
            <td className="px-5 py-2">{modele.format}</td>
            <td className="px-5 py-2">
                <div className="flex items-center gap-1">
                    <div className="w-20">
                        <Saisie type="number" step="0.5" value={data.largeur_mm} onChange={(e) => setData('largeur_mm', e.target.value)} aria-label="Largeur" />
                    </div>
                    ×
                    <div className="w-20">
                        <Saisie type="number" step="0.5" value={data.hauteur_mm} onChange={(e) => setData('hauteur_mm', e.target.value)} aria-label="Hauteur" />
                    </div>
                    mm
                </div>
            </td>
            <td className="px-5 py-2">
                <div className="w-28">
                    <Liste value={data.dpi} onChange={(e) => setData('dpi', e.target.value)} aria-label="Résolution">
                        <option value="203">203 dpi</option>
                        <option value="300">300 dpi</option>
                        <option value="600">600 dpi</option>
                    </Liste>
                </div>
            </td>
            <td className="px-5 py-2">
                <input type="checkbox" checked={data.actif} onChange={(e) => setData('actif', e.target.checked)} aria-label="Actif" />
            </td>
            <td className="px-5 py-2 text-right">
                <Bouton variante="secondaire" disabled={!isDirty || processing} onClick={() => put(`/admin/modeles/${modele.id}`, { preserveScroll: true })}>
                    Enregistrer
                </Bouton>
            </td>
        </tr>
    );
}

function LigneEmplacement({ emplacement }) {
    const { data, setData, put, processing, isDirty } = useForm({ nom: emplacement.nom, actif: emplacement.actif });
    return (
        <li className="flex flex-wrap items-center gap-2 py-1.5">
            <div className="w-64">
                <Saisie value={data.nom} onChange={(e) => setData('nom', e.target.value)} aria-label="Nom de l’emplacement" />
            </div>
            {emplacement.espace && <Pastille>{emplacement.espace}</Pastille>}
            {emplacement.vente && <Pastille couleur="vert">Shop</Pastille>}
            <label className="flex items-center gap-1.5 text-sm text-slate-700">
                <input type="checkbox" checked={data.actif} onChange={(e) => setData('actif', e.target.checked)} />
                Actif
            </label>
            {isDirty && (
                <Bouton variante="secondaire" disabled={processing} onClick={() => put(`/admin/emplacements/${emplacement.id}`, { preserveScroll: true })}>
                    Enregistrer
                </Bouton>
            )}
        </li>
    );
}

function Site({ site }) {
    const { data, setData, put, processing, isDirty } = useForm({ nom: site.nom, actif: site.actif });
    return (
        <details className="rounded-md ring-1 ring-slate-200">
            <summary className="flex cursor-pointer flex-wrap items-center gap-2 px-4 py-2.5">
                <span className="font-medium">{site.nom}</span>
                <Pastille>{site.type === 'entrepot' ? 'Entrepôt' : 'Centre de service'}</Pastille>
                {!site.actif && <Pastille couleur="rouge">Désactivé</Pastille>}
                <span className="text-xs text-slate-500">{site.emplacements.length} emplacement(s)</span>
            </summary>
            <div className="border-t border-slate-100 px-4 py-3">
                <div className="mb-3 flex flex-wrap items-center gap-2">
                    <div className="w-72">
                        <Saisie value={data.nom} onChange={(e) => setData('nom', e.target.value)} aria-label="Nom du site" />
                    </div>
                    <label className="flex items-center gap-1.5 text-sm text-slate-700">
                        <input type="checkbox" checked={data.actif} onChange={(e) => setData('actif', e.target.checked)} />
                        Site actif
                    </label>
                    {isDirty && (
                        <Bouton variante="secondaire" disabled={processing} onClick={() => put(`/admin/sites/${site.id}`, { preserveScroll: true })}>
                            Enregistrer
                        </Bouton>
                    )}
                </div>
                <ul className="divide-y divide-slate-100">
                    {site.emplacements.map((e) => (
                        <LigneEmplacement key={e.id} emplacement={e} />
                    ))}
                </ul>
            </div>
        </details>
    );
}

function Sites({ sites }) {
    const { data, setData, post, processing, errors, reset } = useForm({ ville: '' });
    return (
        <div className="space-y-3">
            {sites.map((s) => (
                <Site key={`${s.id}-${s.nom}-${s.actif}`} site={s} />
            ))}
            <form
                className="flex flex-wrap items-end gap-3 pt-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    post('/admin/sites', { preserveScroll: true, onSuccess: () => reset() });
                }}
            >
                <Champ libelle="Nouveau centre de service (ville)" erreur={errors.ville} className="w-72">
                    <Saisie value={data.ville} onChange={(e) => setData('ville', e.target.value)} placeholder="Daloa, Korhogo…" />
                </Champ>
                <Bouton type="submit" disabled={processing}>
                    Ajouter le centre
                </Bouton>
                <p className="w-full text-xs text-slate-500">Un centre est créé avec trois emplacements : Shop (prêt à la vente), Retour SAV et Défectueux.</p>
            </form>
        </div>
    );
}

export default function Parametres({ societe, imprimantes, modeles, sites }) {
    const [edition, setEdition] = useState(null);

    return (
        <AppLayout titre="Paramètres">
            <div className="space-y-6">
                <Carte titre="Société">
                    <Societe societe={societe} />
                </Carte>

                <Carte titre="Sites et emplacements">
                    <Sites sites={sites} />
                </Carte>

                <Carte titre="Imprimantes autorisées" actions={edition === null && <Bouton variante="secondaire" onClick={() => setEdition('nouvelle')}>Ajouter</Bouton>}>
                    {edition === 'nouvelle' && <FormImprimante onFermer={() => setEdition(null)} />}
                    <Tableau colonnes={[{ libelle: 'Nom' }, { libelle: 'Adresse' }, { libelle: 'Modèle' }, { libelle: 'Résolution' }, { libelle: 'État' }, { libelle: '' }]}>
                        {imprimantes.map((i) =>
                            edition === i.id ? (
                                <tr key={i.id}>
                                    <td colSpan={6} className="px-5 py-2">
                                        <FormImprimante imprimante={i} onFermer={() => setEdition(null)} />
                                    </td>
                                </tr>
                            ) : (
                                <tr key={i.id}>
                                    <td className="px-5 py-2 font-medium">{i.nom}</td>
                                    <td className="px-5 py-2 font-mono text-xs">{i.adresse}</td>
                                    <td className="px-5 py-2">{i.modele}</td>
                                    <td className="px-5 py-2">{i.dpi} dpi</td>
                                    <td className="px-5 py-2">{i.actif ? <Pastille couleur="vert">Active</Pastille> : <Pastille>Désactivée</Pastille>}</td>
                                    <td className="px-5 py-2 text-right">
                                        <Bouton variante="discret" onClick={() => setEdition(i.id)}>
                                            Modifier
                                        </Bouton>
                                    </td>
                                </tr>
                            ),
                        )}
                    </Tableau>
                </Carte>

                <Carte titre="Modèles d’étiquette">
                    <p className="mb-3 text-sm text-slate-600">Le gabarit est fixé dans le code (aucun ZPL saisi à la main) ; seuls le format, la résolution et l’activation sont réglables.</p>
                    <Tableau colonnes={[{ libelle: 'Modèle' }, { libelle: 'Format' }, { libelle: 'Dimensions' }, { libelle: 'Résolution' }, { libelle: 'Actif' }, { libelle: '' }]}>
                        {modeles.map((m) => (
                            <LigneModele key={m.id} modele={m} />
                        ))}
                    </Tableau>
                </Carte>
            </div>
        </AppLayout>
    );
}
