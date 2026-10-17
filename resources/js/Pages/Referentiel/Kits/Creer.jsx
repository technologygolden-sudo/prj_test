import { useForm } from '@inertiajs/react';
import AppLayout from '../../../Layouts/AppLayout';
import EditeurNomenclature from '../../../Components/EditeurNomenclature';
import ChampsTypeKit from '../../../Components/ChampsTypeKit';
import { Bouton, Carte } from '../../../Components/ui';

export default function Creer({ categories, fabricants, articles }) {
    const { data, setData, post, transform, processing, errors } = useForm({
        reference: '',
        partno: '',
        nom: '',
        categorie_id: '',
        fabricant_id: '',
        cout_unitaire: '',
        porteur_id: '',
        lignes: [],
    });

    const envoyer = (e) => {
        e.preventDefault();
        transform((d) => ({ ...d, categorie_id: d.categorie_id || null, fabricant_id: d.fabricant_id || null, cout_unitaire: d.cout_unitaire === '' ? null : d.cout_unitaire }));
        post('/kits');
    };

    return (
        <AppLayout titre="Nouveau type de kit">
            <form onSubmit={envoyer} className="space-y-6">
                <Carte titre="Type de kit">
                    <ChampsTypeKit data={data} setData={setData} errors={errors} categories={categories} fabricants={fabricants} />
                </Carte>
                <Carte titre="Nomenclature de la version 1">
                    <EditeurNomenclature
                        articles={articles}
                        porteurId={data.porteur_id}
                        lignes={data.lignes}
                        erreurs={errors}
                        onChange={({ porteurId, lignes }) => setData((d) => ({ ...d, porteur_id: porteurId, lignes }))}
                    />
                </Carte>
                <div className="flex gap-2">
                    <Bouton type="submit" disabled={processing}>
                        Créer le type de kit
                    </Bouton>
                    <Bouton variante="secondaire" href="/kits">
                        Annuler
                    </Bouton>
                </div>
            </form>
        </AppLayout>
    );
}
