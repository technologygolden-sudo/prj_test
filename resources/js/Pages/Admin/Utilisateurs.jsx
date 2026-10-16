import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import { Bouton, Carte, Champ, Liste, Pastille, Saisie, Tableau } from '../../Components/ui';

function CasesRoles({ roles, valeur, onChange, erreur }) {
    return (
        <Champ libelle="Rôles" erreur={erreur}>
            <div className="flex flex-wrap gap-x-4 gap-y-1">
                {roles.map((r) => (
                    <label key={r} className="flex items-center gap-1.5 text-sm text-slate-700">
                        <input
                            type="checkbox"
                            checked={valeur.includes(r)}
                            onChange={(e) => onChange(e.target.checked ? [...valeur, r] : valeur.filter((x) => x !== r))}
                        />
                        {r}
                    </label>
                ))}
            </div>
        </Champ>
    );
}

function ChoixSite({ sites, valeur, onChange, erreur }) {
    return (
        <Champ libelle="Site" erreur={erreur} aide="Vide ou entrepôt : visibilité globale. Centre de service : uniquement le stock de ce site.">
            <Liste value={valeur ?? ''} onChange={(e) => onChange(e.target.value || null)}>
                <option value="">Aucun (visibilité globale)</option>
                {sites.map((s) => (
                    <option key={s.id} value={s.id}>
                        {s.nom}
                    </option>
                ))}
            </Liste>
        </Champ>
    );
}

function Creation({ roles, sites }) {
    const { data, setData, post, processing, errors, reset } = useForm({ login: '', name: '', email: '', password: '', roles: [], site_id: null });

    return (
        <form
            className="grid gap-4 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                post('/admin/utilisateurs', { preserveScroll: true, onSuccess: () => reset() });
            }}
        >
            <Champ libelle="Login" htmlFor="login" erreur={errors.login}>
                <Saisie id="login" value={data.login} onChange={(e) => setData('login', e.target.value.toLowerCase())} />
            </Champ>
            <Champ libelle="Nom complet" htmlFor="name" erreur={errors.name}>
                <Saisie id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
            </Champ>
            <Champ libelle="E-mail" htmlFor="email" erreur={errors.email}>
                <Saisie id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
            </Champ>
            <Champ libelle="Mot de passe provisoire" htmlFor="password" erreur={errors.password} aide="12 caractères minimum, majuscules, minuscules et chiffres.">
                <Saisie id="password" type="password" autoComplete="new-password" value={data.password} onChange={(e) => setData('password', e.target.value)} />
            </Champ>
            <ChoixSite sites={sites} valeur={data.site_id} onChange={(v) => setData('site_id', v)} erreur={errors.site_id} />
            <div className="sm:col-span-2">
                <CasesRoles roles={roles} valeur={data.roles} onChange={(v) => setData('roles', v)} erreur={errors.roles} />
            </div>
            <div>
                <Bouton type="submit" disabled={processing}>
                    Créer l’utilisateur
                </Bouton>
            </div>
        </form>
    );
}

function Edition({ utilisateur, roles, sites, onFermer }) {
    const { data, setData, put, processing, errors } = useForm({
        name: utilisateur.name,
        email: utilisateur.email,
        actif: utilisateur.actif,
        roles: utilisateur.roles,
        site_id: utilisateur.site_id,
    });

    return (
        <tr>
            <td colSpan={7} className="bg-slate-50 px-5 py-4">
                <form
                    className="grid gap-4 sm:grid-cols-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        put(`/admin/utilisateurs/${utilisateur.id}`, { preserveScroll: true, onSuccess: onFermer });
                    }}
                >
                    <Champ libelle="Nom complet" erreur={errors.name}>
                        <Saisie value={data.name} onChange={(e) => setData('name', e.target.value)} />
                    </Champ>
                    <Champ libelle="E-mail" erreur={errors.email}>
                        <Saisie type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                    </Champ>
                    <ChoixSite sites={sites} valeur={data.site_id} onChange={(v) => setData('site_id', v)} erreur={errors.site_id} />
                    <div className="sm:col-span-2">
                        <CasesRoles roles={roles} valeur={data.roles} onChange={(v) => setData('roles', v)} erreur={errors.roles} />
                    </div>
                    <label className="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" checked={data.actif} onChange={(e) => setData('actif', e.target.checked)} />
                        Compte actif
                    </label>
                    <div className="flex flex-wrap gap-2 sm:col-span-2">
                        <Bouton type="submit" disabled={processing}>
                            Enregistrer
                        </Bouton>
                        <Bouton variante="secondaire" onClick={onFermer}>
                            Annuler
                        </Bouton>
                        {utilisateur.mfa && (
                            <Bouton
                                variante="danger"
                                onClick={() =>
                                    confirm(`Réinitialiser la double authentification de ${utilisateur.login} ?`) &&
                                    router.post(`/admin/utilisateurs/${utilisateur.id}/reinitialiser-mfa`, {}, { preserveScroll: true, onSuccess: onFermer })
                                }
                            >
                                Réinitialiser la MFA
                            </Bouton>
                        )}
                    </div>
                </form>
            </td>
        </tr>
    );
}

export default function Utilisateurs({ utilisateurs, roles, sites }) {
    const { auth } = usePage().props;
    const [enEdition, setEnEdition] = useState(null);

    return (
        <AppLayout titre="Utilisateurs">
            <div className="space-y-6">
                <Carte>
                    <Tableau colonnes={[{ libelle: 'Login' }, { libelle: 'Nom' }, { libelle: 'Rôles' }, { libelle: 'Site' }, { libelle: 'État' }, { libelle: 'Dernière connexion' }, { libelle: '' }]}>
                        {utilisateurs.map((u) => [
                            <tr key={u.id}>
                                <td className="px-5 py-2 font-medium">{u.login}</td>
                                <td className="px-5 py-2">{u.name}</td>
                                <td className="px-5 py-2 text-slate-600">{u.roles.join(', ')}</td>
                                <td className="px-5 py-2 text-slate-600">{u.site ?? 'Global'}</td>
                                <td className="px-5 py-2">
                                    <div className="flex flex-wrap gap-1">
                                        {u.actif ? <Pastille couleur="vert">Actif</Pastille> : <Pastille couleur="rouge">Désactivé</Pastille>}
                                        {u.mfa && <Pastille>MFA</Pastille>}
                                    </div>
                                </td>
                                <td className="px-5 py-2 text-slate-600">{u.derniere_connexion ?? '—'}</td>
                                <td className="px-5 py-2 text-right">
                                    {u.id !== auth.user.id && enEdition !== u.id && (
                                        <Bouton variante="discret" onClick={() => setEnEdition(u.id)}>
                                            Modifier
                                        </Bouton>
                                    )}
                                </td>
                            </tr>,
                            enEdition === u.id && <Edition key={`e${u.id}`} utilisateur={u} roles={roles} sites={sites} onFermer={() => setEnEdition(null)} />,
                        ])}
                    </Tableau>
                </Carte>
                <Carte titre="Nouvel utilisateur">
                    <Creation roles={roles} sites={sites} />
                </Carte>
            </div>
        </AppLayout>
    );
}
