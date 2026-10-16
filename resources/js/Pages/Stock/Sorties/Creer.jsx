import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../Layouts/AppLayout';
import { postJson } from '../../../Components/http';
import { Bouton, Carte, Champ, Liste, Pastille, Saisie, Tableau } from '../../../Components/ui';

export default function Creer() {
    const { data, setData, post, processing, errors } = useForm({ type: 'expedition', destinataire: '', document: '', lignes: [] });
    const [code, setCode] = useState('');
    const [message, setMessage] = useState(null);

    const scanner = async () => {
        const valeur = code.trim();
        setCode('');
        if (!valeur) {
            return;
        }
        try {
            const r = await postJson('/sorties/verifier', { code: valeur });
            if (data.lignes.some((l) => l.type === r.type && l.id === r.id)) {
                setMessage({ alerte: true, texte: `${r.libelle} est déjà dans la liste.` });
                return;
            }
            setData('lignes', [...data.lignes, { ...r, quantite: r.max }]);
            setMessage({ alerte: false, texte: `${r.libelle} ajouté.` });
        } catch (e) {
            setMessage({ alerte: true, texte: e.message });
        }
    };

    return (
        <AppLayout titre="Nouvelle sortie de stock">
            {data.type === 'expedition' && (
                <p className="mb-4 rounded-md bg-slate-50 p-3 text-sm text-slate-700 ring-1 ring-slate-200">Seuls les éléments rangés dans un shop peuvent être remis à un technicien. La livraison au client se suit ensuite dans le CRM.</p>
            )}
            <form
                className="space-y-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    post('/sorties');
                }}
            >
                <Carte titre="Sortie">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Champ libelle="Type" erreur={errors.type}>
                            <Liste value={data.type} onChange={(e) => setData('type', e.target.value)}>
                                <option value="expedition">Remise à un technicien (livraison, installation)</option>
                                <option value="rebut">Mise au rebut (hors d’usage définitif)</option>
                                <option value="retour_fournisseur">Retour fournisseur</option>
                            </Liste>
                        </Champ>
                        <Champ libelle={data.type === 'expedition' ? 'Technicien' : 'Destinataire'} erreur={errors.destinataire}>
                            <Saisie value={data.destinataire} onChange={(e) => setData('destinataire', e.target.value)} placeholder={data.type === 'expedition' ? 'Nom du technicien' : 'Facultatif'} />
                        </Champ>
                        <Champ libelle={data.type === 'expedition' ? 'N° de commande CRM' : 'Document'} erreur={errors.document}>
                            <Saisie value={data.document} onChange={(e) => setData('document', e.target.value)} placeholder="Bon de sortie, n° de commande…" />
                        </Champ>
                    </div>
                </Carte>

                <Carte titre={`Éléments (${data.lignes.length})`}>
                    <div className="mb-4 flex max-w-lg gap-2">
                        <Saisie
                            autoFocus
                            value={code}
                            onChange={(e) => setCode(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    scanner();
                                }
                            }}
                            placeholder="Scanner un kit ou une unité"
                            aria-label="Scanner un kit ou une unité"
                        />
                        <Bouton variante="secondaire" onClick={scanner}>
                            Ajouter
                        </Bouton>
                    </div>
                    {message && <p className={`mb-3 text-sm ${message.alerte ? 'text-amber-800' : 'text-green-800'}`}>{message.texte}</p>}
                    {errors.lignes && <p className="mb-3 text-sm text-red-700">{errors.lignes}</p>}
                    <Tableau colonnes={[{ libelle: 'Élément' }, { libelle: 'Emplacement' }, { libelle: 'Quantité', droite: true }, { libelle: '' }]} vide={data.lignes.length === 0 ? 'Scannez les éléments qui sortent.' : null}>
                        {data.lignes.map((l, i) => (
                            <tr key={`${l.type}-${l.id}`}>
                                <td className="px-5 py-2">
                                    {l.type === 'kit' ? <Pastille couleur="vert">Kit</Pastille> : <Pastille>Article</Pastille>} {l.libelle}
                                </td>
                                <td className="px-5 py-2 text-slate-600">{l.emplacement ?? '—'} {data.type === 'expedition' && !l.shop && <Pastille couleur="rouge">Hors shop</Pastille>}</td>
                                <td className="px-5 py-2 text-right">
                                    {l.max > 1 ? (
                                        <div className="ml-auto w-24">
                                            <Saisie
                                                type="number"
                                                min={1}
                                                max={l.max}
                                                value={l.quantite}
                                                onChange={(e) => setData('lignes', data.lignes.map((x, j) => (j === i ? { ...x, quantite: e.target.value } : x)))}
                                                aria-label="Quantité"
                                            />
                                        </div>
                                    ) : (
                                        1
                                    )}
                                </td>
                                <td className="px-5 py-2 text-right">
                                    <Bouton variante="discret" onClick={() => setData('lignes', data.lignes.filter((_, j) => j !== i))}>
                                        Retirer
                                    </Bouton>
                                </td>
                            </tr>
                        ))}
                    </Tableau>
                </Carte>

                <div className="flex gap-2">
                    <Bouton type="submit" disabled={processing || data.lignes.length === 0}>
                        Valider la sortie
                    </Bouton>
                    <Bouton variante="secondaire" href="/sorties">
                        Annuler
                    </Bouton>
                </div>
            </form>
        </AppLayout>
    );
}
