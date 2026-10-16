import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import { Bouton, Carte, Saisie, Statut, Tableau } from '../../Components/ui';

export default function Recherche({ q, resultats }) {
    const [valeur, setValeur] = useState(q);

    return (
        <AppLayout titre="Recherche">
            <Carte>
                <form
                    className="mb-4 flex gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get('/recherche', { q: valeur });
                    }}
                >
                    <Saisie autoFocus value={valeur} onChange={(e) => setValeur(e.target.value)} placeholder="Scanner un QR code ou un code-barres, ou saisir une référence" className="max-w-lg" aria-label="Code à rechercher" />
                    <Bouton type="submit">Rechercher</Bouton>
                </form>
                {q && resultats.length === 0 && <p className="text-sm text-slate-600">Aucun résultat pour « {q} ».</p>}
                {resultats.length > 1 && (
                    <Tableau colonnes={[{ libelle: 'Type' }, { libelle: 'Désignation' }, { libelle: 'UID' }, { libelle: 'Statut' }]}>
                        {resultats.map((r, i) => (
                            <tr key={i} className="hover:bg-slate-50">
                                <td className="px-5 py-2">{r.type}</td>
                                <td className="px-5 py-2">
                                    <Link href={r.url} className="font-medium text-green-800 hover:underline">
                                        {r.libelle}
                                    </Link>
                                </td>
                                <td className="px-5 py-2 font-mono text-xs">{r.detail ?? '—'}</td>
                                <td className="px-5 py-2">{r.statut ? <Statut valeur={r.statut} /> : '—'}</td>
                            </tr>
                        ))}
                    </Tableau>
                )}
            </Carte>
        </AppLayout>
    );
}
