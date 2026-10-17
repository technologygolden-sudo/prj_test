import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../Layouts/AppLayout';
import ChampsTypeKit from '../../../Components/ChampsTypeKit';
import EditeurNomenclature from '../../../Components/EditeurNomenclature';
import { Bouton, Carte, Pastille, Tableau, usePeut } from '../../../Components/ui';

function InfosKit({ kit, categories, fabricants }) {
    const peut = usePeut();
    const [edition, setEdition] = useState(false);
    const { data, setData, put, transform, processing, errors } = useForm({
        reference: kit.reference,
        partno: kit.partno,
        nom: kit.nom,
        categorie_id: kit.categorie_id ?? '',
        fabricant_id: kit.fabricant_id ?? '',
        cout_unitaire: kit.cout_unitaire ?? '',
    });

    if (edition) {
        return (
            <Carte titre="Type de kit">
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        transform((d) => ({ ...d, categorie_id: d.categorie_id || null, fabricant_id: d.fabricant_id || null, cout_unitaire: d.cout_unitaire === '' ? null : d.cout_unitaire }));
                        put(`/kits/${kit.id}`, { preserveScroll: true, onSuccess: () => setEdition(false) });
                    }}
                >
                    <ChampsTypeKit data={data} setData={setData} errors={errors} categories={categories} fabricants={fabricants} />
                    <div className="flex gap-2">
                        <Bouton type="submit" disabled={processing}>
                            Enregistrer
                        </Bouton>
                        <Bouton variante="secondaire" onClick={() => setEdition(false)}>
                            Annuler
                        </Bouton>
                    </div>
                </form>
            </Carte>
        );
    }

    return (
        <Carte titre="Type de kit" actions={peut('kits.gerer') && <Bouton variante="secondaire" onClick={() => setEdition(true)}>Modifier</Bouton>}>
            <dl className="grid gap-4 text-sm sm:grid-cols-5">
                {[
                    ['Référence CRM', kit.reference],
                    ['PartNo', kit.partno],
                    ['Catégorie', kit.categorie ?? '—'],
                    ['Fabricant', kit.fabricant ?? '—'],
                    ['Coût unitaire', kit.cout_unitaire ? `${Number(kit.cout_unitaire).toLocaleString('fr-FR')} FCFA` : 'Somme des articles'],
                ].map(([libelle, valeur]) => (
                    <div key={libelle}>
                        <dt className="text-slate-500">{libelle}</dt>
                        <dd className="font-medium text-slate-900">{valeur}</dd>
                    </div>
                ))}
            </dl>
        </Carte>
    );
}

function Version({ version, articles }) {
    const peut = usePeut();
    const brouillon = !version.verrouillee;
    const { data, setData, put, setDefaults, processing, errors, isDirty } = useForm({
        porteur_id: version.porteur_id,
        lignes: version.lignes.map((l) => ({ article_id: l.article_id, quantite: l.quantite })),
    });

    const titre = (
        <span className="flex items-center gap-2">
            Version {version.numero}
            {version.active && <Pastille couleur="vert">Active</Pastille>}
            {version.verrouillee && !version.active && <Pastille>Archivée</Pastille>}
            {brouillon && <Pastille couleur="orange">Brouillon</Pastille>}
            {brouillon && !version.porteur_id && <Pastille couleur="rouge">Porteur à choisir</Pastille>}
        </span>
    );

    const actions = brouillon && (
        <>
            {peut('kits.verrouiller') && (
                <Bouton
                    disabled={isDirty || !version.porteur_id}
                    title={isDirty ? 'Enregistrez d’abord la nomenclature' : !version.porteur_id ? 'Choisissez d’abord le porteur de série' : undefined}
                    onClick={() =>
                        confirm(`Verrouiller la version ${version.numero} ? Elle ne sera plus modifiable et deviendra la version active.`) &&
                        router.post(`/versions/${version.id}/verrouiller`, {}, { preserveScroll: true })
                    }
                >
                    Verrouiller
                </Bouton>
            )}
            {peut('kits.gerer') && (
                <Bouton
                    variante="danger"
                    onClick={() => confirm(`Supprimer le brouillon de la version ${version.numero} ?`) && router.delete(`/versions/${version.id}`, { preserveScroll: true })}
                >
                    Supprimer
                </Bouton>
            )}
        </>
    );

    if (brouillon && peut('kits.gerer')) {
        return (
            <Carte titre={titre} actions={actions}>
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        put(`/versions/${version.id}`, { preserveScroll: true, onSuccess: () => setDefaults() });
                    }}
                >
                    <EditeurNomenclature
                        articles={articles}
                        porteurId={data.porteur_id}
                        lignes={data.lignes}
                        erreurs={errors}
                        onChange={({ porteurId, lignes }) => setData((d) => ({ ...d, porteur_id: porteurId, lignes }))}
                    />
                    <Bouton type="submit" disabled={processing || !isDirty}>
                        Enregistrer la nomenclature
                    </Bouton>
                </form>
            </Carte>
        );
    }

    return (
        <Carte titre={titre} actions={actions}>
            {version.verrouillee && (
                <p className="mb-3 text-xs text-slate-500">
                    Verrouillée le {version.verrouillee_le}
                    {version.verrouillee_par && ` par ${version.verrouillee_par}`}
                </p>
            )}
            <Tableau colonnes={[{ libelle: 'Article' }, { libelle: 'Désignation' }, { libelle: 'Quantité', droite: true }]}>
                {version.porteur ? (
                    <tr className="bg-green-50/50">
                        <td className="px-5 py-2 font-medium">{version.porteur.reference}</td>
                        <td className="px-5 py-2">
                            {version.porteur.nom} <Pastille couleur="vert">Porteur de série</Pastille>
                        </td>
                        <td className="px-5 py-2 text-right tabular-nums">1</td>
                    </tr>
                ) : (
                    <tr>
                        <td colSpan={3} className="px-5 py-2">
                            <Pastille couleur="orange">Porteur de série à choisir</Pastille>
                        </td>
                    </tr>
                )}
                {version.lignes.map((l) => (
                    <tr key={l.article_id}>
                        <td className="px-5 py-2 font-medium">{l.reference}</td>
                        <td className="px-5 py-2">{l.nom}</td>
                        <td className="px-5 py-2 text-right tabular-nums">{l.quantite}</td>
                    </tr>
                ))}
            </Tableau>
        </Carte>
    );
}

export default function Fiche({ kit, versions, articles, categories, fabricants, kitsAssembles }) {
    const peut = usePeut();
    const aBrouillon = versions.some((v) => !v.verrouillee);
    const aVerrouillee = versions.some((v) => v.verrouillee);

    return (
        <AppLayout
            titre={`${kit.reference} · ${kit.nom}`}
            actions={
                <>
                    {peut('kits.gerer') && !aBrouillon && (
                        <Bouton onClick={() => router.post(`/kits/${kit.id}/versions`, {}, { preserveScroll: true })}>Nouvelle version</Bouton>
                    )}
                    {peut('kits.gerer') && !aVerrouillee && (
                        <Bouton variante="danger" onClick={() => confirm(`Supprimer le type de kit ${kit.reference} ?`) && router.delete(`/kits/${kit.id}`)}>
                            Supprimer
                        </Bouton>
                    )}
                    <Bouton variante="secondaire" href="/kits">
                        Retour
                    </Bouton>
                </>
            }
        >
            <div className="space-y-6">
                <InfosKit kit={kit} categories={categories} fabricants={fabricants} />
                <p className="text-sm text-slate-600">
                    {kitsAssembles} kit{kitsAssembles > 1 ? 's' : ''} assemblé{kitsAssembles > 1 ? 's' : ''} avec ce type. Une version verrouillée est figée :
                    toute évolution passe par une nouvelle version.
                </p>
                {versions.map((v) => (
                    <Version key={`${v.id}-${v.verrouillee}`} version={v} articles={articles} />
                ))}
            </div>
        </AppLayout>
    );
}
