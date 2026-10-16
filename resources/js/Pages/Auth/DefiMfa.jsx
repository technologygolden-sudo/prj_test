import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthLayout from '../../Layouts/AuthLayout';
import { Bouton, Champ, Saisie } from '../../Components/ui';

export default function DefiMfa() {
    const [secours, setSecours] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ code: '', recovery_code: '' });

    const envoyer = (e) => {
        e.preventDefault();
        post('/two-factor-challenge', { onFinish: () => reset() });
    };

    return (
        <AuthLayout
            titre="Double authentification"
            sousTitre={secours ? 'Saisissez un de vos codes de secours.' : 'Saisissez le code à 6 chiffres de votre application d’authentification.'}
        >
            <form onSubmit={envoyer} className="space-y-4">
                {secours ? (
                    <Champ libelle="Code de secours" htmlFor="recovery_code" erreur={errors.recovery_code}>
                        <Saisie id="recovery_code" autoFocus autoComplete="one-time-code" value={data.recovery_code} onChange={(e) => setData('recovery_code', e.target.value)} />
                    </Champ>
                ) : (
                    <Champ libelle="Code" htmlFor="code" erreur={errors.code}>
                        <Saisie
                            id="code"
                            autoFocus
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={6}
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value)}
                        />
                    </Champ>
                )}
                <Bouton type="submit" className="w-full" disabled={processing}>
                    Valider
                </Bouton>
            </form>
            <button
                type="button"
                className="mt-4 text-sm text-green-800 hover:underline"
                onClick={() => {
                    setSecours(!secours);
                    reset();
                }}
            >
                {secours ? 'Utiliser le code de l’application' : 'Utiliser un code de secours'}
            </button>
        </AuthLayout>
    );
}
