import { router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { Bouton, Carte, Champ, Liste, Pastille, Saisie } from '../../Components/ui';

function EditeurRole({ role, permissions }) {
    const { auth } = usePage().props;
    const verrouille = role.systeme || auth.user.roles.includes(role.name);
    const { data, setData, put, processing, errors, isDirty } = useForm({ permissions: role.permissions, actif: role.actif, code_mfa: '' });

    const sensiblesChangees = permissions.some((p) => p.sensible && role.permissions.includes(p.name) !== data.permissions.includes(p.name));
    const modules = [...new Set(permissions.map((p) => p.module))];

    return (
        <Carte
            titre={
                <span className="flex items-center gap-2">
                    {role.name}
                    {role.systeme && <Pastille couleur="vert">Rôle système</Pastille>}
                    {!role.actif && <Pastille>Désactivé</Pastille>}
                    <span className="text-sm font-normal text-slate-500">{role.utilisateurs} utilisateur(s)</span>
                </span>
            }
            actions={
                !role.systeme &&
                role.utilisateurs === 0 && (
                    <Bouton variante="danger" onClick={() => confirm(`Supprimer le rôle ${role.name} ?`) && router.delete(`/admin/roles/${role.id}`, { preserveScroll: true })}>
                        Supprimer
                    </Bouton>
                )
            }
        >
            {verrouille && (
                <p className="mb-3 text-sm text-slate-600">
                    {role.systeme ? 'Le rôle Administrateur garde toujours tout le catalogue (RG16).' : 'Vous détenez ce rôle : un autre administrateur doit le modifier (RG18).'}
                </p>
            )}
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    put(`/admin/roles/${role.id}`, { preserveScroll: true, onSuccess: () => setData('code_mfa', '') });
                }}
            >
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {modules.map((m) => (
                        <fieldset key={m}>
                            <legend className="mb-1 text-xs font-semibold tracking-wide text-slate-500 uppercase">{m}</legend>
                            {permissions
                                .filter((p) => p.module === m)
                                .map((p) => (
                                    <label key={p.name} className="flex items-start gap-2 py-0.5 text-sm text-slate-700">
                                        <input
                                            type="checkbox"
                                            className="mt-1"
                                            disabled={verrouille}
                                            checked={data.permissions.includes(p.name)}
                                            onChange={(e) => setData('permissions', e.target.checked ? [...data.permissions, p.name] : data.permissions.filter((x) => x !== p.name))}
                                        />
                                        <span>
                                            {p.libelle}
                                            {p.sensible && <span className="ml-1 text-amber-700" title="Permission sensible : MFA exigée">★</span>}
                                            <span className="block font-mono text-xs text-slate-400">{p.name}</span>
                                        </span>
                                    </label>
                                ))}
                        </fieldset>
                    ))}
                </div>
                {!verrouille && (
                    <div className="mt-4 flex flex-wrap items-end gap-3 border-t border-slate-100 pt-4">
                        <label className="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" checked={data.actif} onChange={(e) => setData('actif', e.target.checked)} />
                            Rôle actif
                        </label>
                        {sensiblesChangees && (
                            <Champ libelle="Code de double authentification" erreur={errors.code_mfa} aide="Exigé pour une permission ★ (RG19)." className="w-56">
                                <Saisie inputMode="numeric" maxLength={6} value={data.code_mfa} onChange={(e) => setData('code_mfa', e.target.value)} />
                            </Champ>
                        )}
                        <Bouton type="submit" disabled={!isDirty || processing}>
                            Enregistrer
                        </Bouton>
                    </div>
                )}
            </form>
        </Carte>
    );
}

function NouveauRole({ roles }) {
    const { data, setData, post, processing, errors, reset } = useForm({ name: '', copie_de: '' });
    return (
        <form
            className="flex flex-wrap items-end gap-3"
            onSubmit={(e) => {
                e.preventDefault();
                post('/admin/roles', { preserveScroll: true, onSuccess: () => reset() });
            }}
        >
            <Champ libelle="Nom du rôle" erreur={errors.name} className="w-64">
                <Saisie value={data.name} onChange={(e) => setData('name', e.target.value)} />
            </Champ>
            <Champ libelle="Partir de" className="w-64" aide="Les permissions ★ ne sont pas recopiées.">
                <Liste value={data.copie_de} onChange={(e) => setData('copie_de', e.target.value)}>
                    <option value="">Aucune permission</option>
                    {roles.map((r) => (
                        <option key={r.id} value={r.id}>
                            {r.name}
                        </option>
                    ))}
                </Liste>
            </Champ>
            <Bouton type="submit" disabled={processing}>
                Créer le rôle
            </Bouton>
        </form>
    );
}

export default function Roles({ roles, permissions }) {
    return (
        <AppLayout titre="Rôles et permissions">
            <div className="space-y-6">
                {roles.map((r) => (
                    <EditeurRole key={`${r.id}-${r.permissions.join()}-${r.actif}`} role={r} permissions={permissions} />
                ))}
                <Carte titre="Nouveau rôle">
                    <NouveauRole roles={roles} />
                </Carte>
            </div>
        </AppLayout>
    );
}
