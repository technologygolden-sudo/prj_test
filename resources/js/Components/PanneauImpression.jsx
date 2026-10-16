import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { soumettreDansOnglet } from './http';
import { Bouton, Champ, Liste, Saisie, usePeut } from './ui';

/**
 * Impression d'étiquettes (EF04, EF06) : ZPL envoyé à la Zebra, ou PDF ouvert dans un nouvel onglet.
 * Une réimpression exige un motif et la permission dédiée (RG12).
 */
export default function PanneauImpression({ cibles, imprimantes, dejaImprime = false, libelle = 'Imprimer l’étiquette' }) {
    const peut = usePeut();
    const { errors } = usePage().props;
    const [imprimanteId, setImprimanteId] = useState(imprimantes[0]?.id ?? '');
    const [copies, setCopies] = useState(1);
    const [motif, setMotif] = useState('');
    const [envoi, setEnvoi] = useState(false);

    if (!peut('etiquettes.imprimer') || cibles.length === 0) {
        return null;
    }
    if (dejaImprime && !peut('etiquettes.reimprimer')) {
        return <p className="text-sm text-slate-500">Étiquette déjà imprimée. La réimpression demande une permission dédiée.</p>;
    }
    if (imprimantes.length === 0) {
        return <p className="text-sm text-amber-800">Aucune imprimante active : un administrateur doit en déclarer une dans Paramètres.</p>;
    }

    const imprimante = imprimantes.find((i) => Number(i.id) === Number(imprimanteId));
    const donnees = { cibles, imprimante_id: imprimanteId, copies, motif: dejaImprime ? motif : '' };

    const imprimer = () => {
        if (imprimante?.format === 'PDF') {
            soumettreDansOnglet('/etiquettes/imprimer', donnees);
            setTimeout(() => router.reload({ only: ['impressions', 'unites'] }), 1500);
            return;
        }
        setEnvoi(true);
        router.post('/etiquettes/imprimer', donnees, { preserveScroll: true, onFinish: () => setEnvoi(false), onSuccess: () => setMotif('') });
    };

    return (
        <div className="flex flex-wrap items-end gap-3">
            <Champ libelle="Imprimante" erreur={errors.imprimante_id} className="w-64">
                <Liste value={imprimanteId} onChange={(e) => setImprimanteId(e.target.value)}>
                    {imprimantes.map((i) => (
                        <option key={i.id} value={i.id}>
                            {i.nom} ({i.format})
                        </option>
                    ))}
                </Liste>
            </Champ>
            <Champ libelle="Copies" className="w-20">
                <Saisie type="number" min={1} max={100} value={copies} onChange={(e) => setCopies(e.target.value)} />
            </Champ>
            {dejaImprime && (
                <Champ libelle="Motif de réimpression" erreur={errors.motif} className="min-w-56 flex-1">
                    <Saisie value={motif} onChange={(e) => setMotif(e.target.value)} placeholder="Étiquette abîmée, illisible…" />
                </Champ>
            )}
            <Bouton onClick={imprimer} disabled={envoi || (dejaImprime && motif.trim().length < 3)}>
                {dejaImprime ? 'Réimprimer' : libelle}
            </Bouton>
        </div>
    );
}
