import { useForm, usePage } from '@inertiajs/react';
import AuthLayout from '../../Layouts/AuthLayout';
import { Bouton, Champ, Saisie } from '../../Components/ui';

export default function Connexion() {
    const { flash } = usePage().props;
    const { data, setData, post, processing, errors } = useForm({ login: '', password: '', remember: false });

    const envoyer = (e) => {
        e.preventDefault();
        post('/login', { onFinish: () => setData('password', '') });
    };

    return (
        <AuthLayout titre="Connexion" sousTitre="Identifiez-vous pour accéder à l’application.">
            {flash.status && <p className="mb-4 text-sm text-green-800">{flash.status}</p>}
            <form onSubmit={envoyer} className="space-y-4">
                <Champ libelle="Identifiant" htmlFor="login" erreur={errors.login}>
                    <Saisie
                        id="login"
                        autoComplete="username"
                        autoFocus
                        value={data.login}
                        onChange={(e) => setData('login', e.target.value)}
                        invalide={errors.login}
                    />
                </Champ>
                <Champ libelle="Mot de passe" htmlFor="password" erreur={errors.password}>
                    <Saisie
                        id="password"
                        type="password"
                        autoComplete="current-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        invalide={errors.password}
                    />
                </Champ>
                <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" checked={data.remember} onChange={(e) => setData('remember', e.target.checked)} />
                    Rester connecté
                </label>
                <Bouton type="submit" className="w-full" disabled={processing}>
                    Se connecter
                </Bouton>
            </form>
            <p className="mt-4 text-xs text-slate-500">Mot de passe oublié : contactez votre administrateur.</p>
        </AuthLayout>
    );
}
