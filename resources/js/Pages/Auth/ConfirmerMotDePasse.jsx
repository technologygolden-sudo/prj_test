import { useForm } from '@inertiajs/react';
import AuthLayout from '../../Layouts/AuthLayout';
import { Bouton, Champ, Saisie } from '../../Components/ui';

export default function ConfirmerMotDePasse() {
    const { data, setData, post, processing, errors, reset } = useForm({ password: '' });

    return (
        <AuthLayout titre="Confirmez votre mot de passe" sousTitre="Cette action sensible demande de confirmer votre mot de passe.">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post('/user/confirm-password', { onFinish: () => reset() });
                }}
                className="space-y-4"
            >
                <Champ libelle="Mot de passe" htmlFor="password" erreur={errors.password}>
                    <Saisie id="password" type="password" autoFocus autoComplete="current-password" value={data.password} onChange={(e) => setData('password', e.target.value)} />
                </Champ>
                <Bouton type="submit" className="w-full" disabled={processing}>
                    Confirmer
                </Bouton>
            </form>
        </AuthLayout>
    );
}
