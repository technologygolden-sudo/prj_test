import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import { Bouton, Carte, Champ, Pastille, Saisie } from '../../Components/ui';

async function lireJson(url) {
    const reponse = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (reponse.status === 423) {
        return null; // mot de passe à reconfirmer
    }
    return reponse.json();
}

function ActivationMfa() {
    const [qr, setQr] = useState(null);
    const [cle, setCle] = useState(null);
    const { data, setData, post, processing, errors } = useForm({ code: '' });

    useEffect(() => {
        lireJson('/user/two-factor-qr-code').then((r) => r && setQr(r.svg));
        lireJson('/user/two-factor-secret-key').then((r) => r && setCle(r.secretKey));
    }, []);

    return (
        <div className="space-y-4">
            <ol className="list-decimal space-y-1 pl-5 text-sm text-slate-700">
                <li>Ouvrez une application d’authentification (Google Authenticator, Microsoft Authenticator…).</li>
                <li>Scannez ce QR code, ou saisissez la clé à la main.</li>
                <li>Entrez le code à 6 chiffres affiché pour terminer.</li>
            </ol>
            <div className="flex flex-wrap items-center gap-6">
                {qr ? (
                    <div className="rounded-md bg-white p-2 ring-1 ring-slate-200" dangerouslySetInnerHTML={{ __html: qr }} />
                ) : (
                    <p className="text-sm text-slate-500">Chargement du QR code…</p>
                )}
                {cle && (
                    <p className="text-sm text-slate-700">
                        Clé : <code className="rounded bg-slate-100 px-1.5 py-0.5 font-mono">{cle}</code>
                    </p>
                )}
            </div>
            <form
                className="flex flex-wrap items-end gap-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    post('/user/confirmed-two-factor-authentication', { errorBag: 'confirmTwoFactorAuthentication', preserveScroll: true });
                }}
            >
                <Champ libelle="Code" htmlFor="code" erreur={errors.code} className="w-36">
                    <Saisie id="code" inputMode="numeric" maxLength={6} autoComplete="one-time-code" value={data.code} onChange={(e) => setData('code', e.target.value)} />
                </Champ>
                <Bouton type="submit" disabled={processing}>
                    Confirmer l’activation
                </Bouton>
            </form>
        </div>
    );
}

function CodesSecours() {
    const [codes, setCodes] = useState(null);
    const [aConfirmer, setAConfirmer] = useState(false);

    const afficher = () =>
        lireJson('/user/two-factor-recovery-codes').then((r) => {
            if (r === null) {
                setAConfirmer(true);
            } else {
                setCodes(r);
            }
        });

    if (aConfirmer) {
        return (
            <p className="text-sm text-slate-600">
                Pour voir vos codes, <a href="/user/confirm-password" className="text-green-800 underline">confirmez votre mot de passe</a> puis revenez ici.
            </p>
        );
    }

    return codes ? (
        <div>
            <p className="mb-2 text-sm text-slate-700">Conservez ces codes en lieu sûr : chacun permet une connexion si vous perdez votre téléphone.</p>
            <ul className="grid max-w-md grid-cols-2 gap-1 rounded-md bg-slate-50 p-3 font-mono text-sm">
                {codes.map((c) => (
                    <li key={c}>{c}</li>
                ))}
            </ul>
        </div>
    ) : (
        <Bouton variante="secondaire" onClick={afficher}>
            Afficher les codes de secours
        </Bouton>
    );
}

function ChangementMotDePasse() {
    const { flash } = usePage().props;
    const { data, setData, put, processing, errors, reset } = useForm({ current_password: '', password: '', password_confirmation: '' });

    return (
        <form
            className="grid max-w-md gap-4"
            onSubmit={(e) => {
                e.preventDefault();
                put('/user/password', { errorBag: 'updatePassword', preserveScroll: true, onFinish: () => reset() });
            }}
        >
            {flash.status === 'password-updated' && <p className="text-sm text-green-800">Mot de passe modifié.</p>}
            <Champ libelle="Mot de passe actuel" htmlFor="current_password" erreur={errors.current_password}>
                <Saisie id="current_password" type="password" autoComplete="current-password" value={data.current_password} onChange={(e) => setData('current_password', e.target.value)} />
            </Champ>
            <Champ libelle="Nouveau mot de passe" htmlFor="password" erreur={errors.password} aide="12 caractères minimum, avec majuscules, minuscules et chiffres.">
                <Saisie id="password" type="password" autoComplete="new-password" value={data.password} onChange={(e) => setData('password', e.target.value)} />
            </Champ>
            <Champ libelle="Confirmation" htmlFor="password_confirmation">
                <Saisie id="password_confirmation" type="password" autoComplete="new-password" value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)} />
            </Champ>
            <div>
                <Bouton type="submit" disabled={processing}>
                    Changer le mot de passe
                </Bouton>
            </div>
        </form>
    );
}

export default function Securite({ mfaActive, mfaEnCours, mfaExigee }) {
    const { flash } = usePage().props;

    return (
        <AppLayout titre="Sécurité du compte">
            <div className="space-y-6">
                <Carte
                    titre="Double authentification"
                    actions={mfaActive ? <Pastille couleur="vert">Activée</Pastille> : <Pastille couleur="orange">Non activée</Pastille>}
                >
                    {mfaActive && (
                        <div className="space-y-4">
                            {flash.status === 'two-factor-authentication-confirmed' && (
                                <p className="text-sm text-green-800">Double authentification activée.</p>
                            )}
                            <CodesSecours />
                            {!mfaExigee && (
                                <Bouton variante="danger" onClick={() => router.delete('/user/two-factor-authentication', { preserveScroll: true })}>
                                    Désactiver
                                </Bouton>
                            )}
                        </div>
                    )}
                    {mfaEnCours && <ActivationMfa />}
                    {!mfaActive && !mfaEnCours && (
                        <div className="space-y-3">
                            <p className="text-sm text-slate-700">
                                {mfaExigee
                                    ? 'Votre rôle comporte des actions sensibles : la double authentification est obligatoire.'
                                    : 'Ajoutez un code à usage unique à votre mot de passe pour protéger votre compte.'}
                            </p>
                            <Bouton onClick={() => router.post('/user/two-factor-authentication', {}, { preserveScroll: true })}>
                                Activer la double authentification
                            </Bouton>
                            <p className="text-xs text-slate-500">Votre mot de passe peut vous être redemandé : cliquez à nouveau sur Activer après l’avoir confirmé.</p>
                        </div>
                    )}
                </Carte>
                <Carte titre="Mot de passe">
                    <ChangementMotDePasse />
                </Carte>
            </div>
        </AppLayout>
    );
}
