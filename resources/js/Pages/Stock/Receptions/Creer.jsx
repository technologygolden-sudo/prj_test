import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../Layouts/AppLayout';
import { Bouton, Carte, Champ, Liste, Pastille, Saisie } from '../../../Components/ui';

/** Scan des n° de série : la douchette envoie le code puis « Entrée ». */
function ScanSeries({ series, onChange, erreur }) {
    const [code, setCode] = useState('');
    const [doublon, setDoublon] = useState(null);

    const ajouter = () => {
        const valeur = code.trim();
        if (!valeur) {
            return;
        }
        if (series.includes(valeur)) {
            setDoublon(valeur);
        } else {
            onChange([...series, valeur]);
            setDoublon(null);
        }
        setCode('');
    };

    return (
        <div className="mt-2">
            <div className="flex max-w-md gap-2">
                <Saisie
                    value={code}
                    onChange={(e) => setCode(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            ajouter();
                        }
                    }}
                    placeholder="Scanner un n° de série"
                    aria-label="Scanner un n° de série"
                />
                <Bouton variante="secondaire" onClick={ajouter}>
                    Ajouter
                </Bouton>
            </div>
            {doublon && <p className="mt-1 text-sm text-amber-800">{doublon} est déjà dans la liste.</p>}
            {erreur && <p className="mt-1 text-sm text-red-700">{erreur}</p>}
            {series.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1.5">
                    {series.map((s) => (
                        <span key={s} className="inline-flex items-center gap-1 rounded bg-slate-100 px-2 py-0.5 font-mono text-xs">
                            {s}
                            <button type="button" className="text-slate-500 hover:text-red-700" aria-label={`Retirer ${s}`} onClick={() => onChange(series.filter((x) => x !== s))}>
                                ×
                            </button>
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}

export default function Creer({ articles }) {
    const aujourdhui = new Date().toISOString().slice(0, 10);
    const { data, setData, post, processing, errors } = useForm({
        date: aujourdhui,
        num_bl: '',
        fournisseur: '',
        emplacement: '',
        espace: '',
        lignes: [{ article_id: '', quantite: 1, series: [] }],
    });

    const article = (id) => articles.find((a) => Number(a.id) === Number(id));
    const changer = (i, champs) => setData('lignes', data.lignes.map((l, j) => (j === i ? { ...l, ...champs } : l)));

    return (
        <AppLayout titre="Nouvelle réception">
            <form
                className="space-y-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    post('/receptions');
                }}
            >
                <Carte titre="Bon de livraison">
                    <fieldset className="mb-5">
                        <legend className="mb-2 text-sm font-medium text-slate-700">Réception pour</legend>
                        <div className="flex flex-wrap gap-3">
                            {['GREENO', 'TEVIA'].map((espace) => (
                                <label
                                    key={espace}
                                    className={`flex cursor-pointer items-center gap-2 rounded-md px-4 py-2.5 text-sm font-semibold ring-1 ${
                                        data.espace === espace ? 'bg-green-800 text-white ring-green-800' : 'bg-white text-slate-800 ring-slate-300 hover:bg-slate-50'
                                    }`}
                                >
                                    <input type="radio" name="espace" value={espace} checked={data.espace === espace} onChange={() => setData('espace', espace)} className="sr-only" />
                                    {espace}
                                </label>
                            ))}
                        </div>
                        <p className="mt-1 text-xs text-slate-500">Les articles appartiendront à ce propriétaire et arriveront dans sa Réception, à l’entrepôt.</p>
                        {errors.espace && <p className="mt-1 text-sm text-red-700">{errors.espace}</p>}
                    </fieldset>
                    <div className="grid gap-4 sm:grid-cols-4">
                        <Champ libelle="Date" htmlFor="date" erreur={errors.date}>
                            <Saisie id="date" type="date" value={data.date} max={aujourdhui} onChange={(e) => setData('date', e.target.value)} />
                        </Champ>
                        <Champ libelle="N° de BL" htmlFor="num_bl" erreur={errors.num_bl}>
                            <Saisie id="num_bl" value={data.num_bl} onChange={(e) => setData('num_bl', e.target.value)} />
                        </Champ>
                        <Champ libelle="Fournisseur" htmlFor="fournisseur" erreur={errors.fournisseur}>
                            <Saisie id="fournisseur" value={data.fournisseur} onChange={(e) => setData('fournisseur', e.target.value)} />
                        </Champ>
                        <Champ libelle="Repère de rangement" htmlFor="emplacement" erreur={errors.emplacement}>
                            <Saisie id="emplacement" value={data.emplacement} onChange={(e) => setData('emplacement', e.target.value)} placeholder="Allée, étagère…" />
                        </Champ>
                    </div>
                </Carte>

                <Carte titre="Lignes">
                    <div className="space-y-5">
                        {data.lignes.map((ligne, i) => {
                            const a = article(ligne.article_id);
                            return (
                                <div key={i} className="border-b border-slate-100 pb-4 last:border-0">
                                    <div className="flex flex-wrap items-end gap-3">
                                        <Champ libelle={`Article ${i + 1}`} erreur={errors[`lignes.${i}.article_id`]} className="min-w-64 flex-1">
                                            <Liste value={ligne.article_id} onChange={(e) => changer(i, { article_id: e.target.value, series: [], quantite: 1 })}>
                                                <option value="">Choisir…</option>
                                                {articles.map((x) => (
                                                    <option key={x.id} value={x.id}>
                                                        {x.reference} — {x.nom}
                                                    </option>
                                                ))}
                                            </Liste>
                                        </Champ>
                                        {a && !a.est_serialise && (
                                            <Champ libelle="Quantité" erreur={errors[`lignes.${i}.quantite`]} className="w-28">
                                                <Saisie type="number" min={1} value={ligne.quantite} onChange={(e) => changer(i, { quantite: e.target.value })} />
                                            </Champ>
                                        )}
                                        {a?.est_serialise && <Pastille couleur="vert">{ligne.series.length} n° scanné(s)</Pastille>}
                                        {data.lignes.length > 1 && (
                                            <Bouton variante="discret" onClick={() => setData('lignes', data.lignes.filter((_, j) => j !== i))}>
                                                Retirer
                                            </Bouton>
                                        )}
                                    </div>
                                    {a?.est_serialise && (
                                        <ScanSeries
                                            series={ligne.series}
                                            erreur={errors[`lignes.${i}.series`] ?? errors[`lignes.${i}.quantite`]}
                                            onChange={(series) => changer(i, { series, quantite: series.length })}
                                        />
                                    )}
                                    {a && !a.est_serialise && <p className="mt-1 text-xs text-slate-500">Un lot sera créé avec une seule étiquette.</p>}
                                </div>
                            );
                        })}
                    </div>
                    {errors.lignes && <p className="mt-2 text-sm text-red-700">{errors.lignes}</p>}
                    <Bouton variante="secondaire" className="mt-4" onClick={() => setData('lignes', [...data.lignes, { article_id: '', quantite: 1, series: [] }])}>
                        Ajouter une ligne
                    </Bouton>
                </Carte>

                <div className="flex gap-2">
                    <Bouton type="submit" disabled={processing}>
                        Enregistrer la réception
                    </Bouton>
                    <Bouton variante="secondaire" href="/receptions">
                        Annuler
                    </Bouton>
                </div>
            </form>
        </AppLayout>
    );
}
