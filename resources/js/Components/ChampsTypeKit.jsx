import { Champ, Liste, Saisie } from './ui';

export default function ChampsTypeKit({ data, setData, errors, categories, fabricants }) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <Champ libelle="Référence CRM" htmlFor="reference" erreur={errors.reference}>
                <Saisie id="reference" value={data.reference} onChange={(e) => setData('reference', e.target.value)} invalide={errors.reference} />
            </Champ>
            <Champ libelle="PartNo" htmlFor="partno" erreur={errors.partno}>
                <Saisie id="partno" value={data.partno} onChange={(e) => setData('partno', e.target.value)} invalide={errors.partno} />
            </Champ>
            <Champ libelle="Nom" htmlFor="nom" erreur={errors.nom} className="sm:col-span-2">
                <Saisie id="nom" value={data.nom} onChange={(e) => setData('nom', e.target.value)} invalide={errors.nom} />
            </Champ>
            <Champ libelle="Catégorie" htmlFor="categorie_id" erreur={errors.categorie_id}>
                <Liste id="categorie_id" value={data.categorie_id ?? ''} onChange={(e) => setData('categorie_id', e.target.value)}>
                    <option value="">—</option>
                    {categories.map((c) => (
                        <option key={c.id} value={c.id}>
                            {c.libelle}
                        </option>
                    ))}
                </Liste>
            </Champ>
            <Champ libelle="Fabricant" htmlFor="fabricant_id" erreur={errors.fabricant_id}>
                <Liste id="fabricant_id" value={data.fabricant_id ?? ''} onChange={(e) => setData('fabricant_id', e.target.value)}>
                    <option value="">—</option>
                    {fabricants.map((f) => (
                        <option key={f.id} value={f.id}>
                            {f.nom}
                        </option>
                    ))}
                </Liste>
            </Champ>
        </div>
    );
}
