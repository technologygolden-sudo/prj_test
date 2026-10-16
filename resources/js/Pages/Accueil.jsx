import { Link } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';
import { Bouton, usePeut } from '../Components/ui';

const raccourcis = [
    { libelle: 'Nouveau type de kit', href: '/kits/nouveau', permission: 'kits.gerer' },
    { libelle: 'Assembler un kit', href: '/assemblage', permission: 'assemblage.realiser' },
    { libelle: 'Nouvelle réception', href: '/receptions/nouvelle', permission: 'reception.creer' },
    { libelle: 'Nouveau transfert', href: '/transferts/nouveau', permission: 'transferts.envoyer' },
    { libelle: 'Remise à un technicien', href: '/sorties/nouvelle', permission: 'stock.sortir' },
    { libelle: 'Stock par site', href: '/stock?vue=sites', permission: 'articles.voir' },
];

export default function Accueil({ compteurs }) {
    const peut = usePeut();
    const tuiles = [
        { libelle: 'Articles', valeur: compteurs.articles, href: '/articles', permission: 'articles.voir' },
        { libelle: 'Types de kit', valeur: compteurs.types_kit, href: '/kits', permission: 'articles.voir' },
        { libelle: 'Versions en brouillon', valeur: compteurs.brouillons, href: '/kits', permission: 'articles.voir' },
        { libelle: 'Unités en stock', valeur: compteurs.unites_en_stock, href: '/stock', permission: 'articles.voir' },
        { libelle: 'Kits assemblés en stock', valeur: compteurs.kits_assembles, href: '/kits-assembles', permission: 'articles.voir' },
        { libelle: 'Étiquettes à imprimer', valeur: compteurs.a_imprimer, href: '/stock', permission: 'articles.voir' },
        { libelle: 'Transferts en transit', valeur: compteurs.transferts_en_transit, href: '/transferts?statut=en_transit', permission: ['transferts.envoyer', 'transferts.recevoir'] },
        { libelle: 'Éléments défectueux', valeur: compteurs.defectueux, href: '/stock?etat=defectueux', permission: 'articles.voir' },
    ];
    const actions = raccourcis.filter((r) => peut(r.permission));

    return (
        <AppLayout titre="Accueil">
            {actions.length > 0 && (
                <div className="mb-6 flex flex-wrap gap-2">
                    {actions.map((r) => (
                        <Bouton key={r.href} href={r.href} variante="secondaire">
                            {r.libelle}
                        </Bouton>
                    ))}
                </div>
            )}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {tuiles.map((t) => {
                    const contenu = (
                        <>
                            <p className="text-sm text-slate-600">{t.libelle}</p>
                            <p className="mt-1 text-2xl font-semibold text-slate-900 tabular-nums">{t.valeur}</p>
                        </>
                    );
                    const cls = 'rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200';
                    return t.href && peut(t.permission) ? (
                        <Link key={t.libelle} href={t.href} className={`${cls} hover:ring-green-300`}>
                            {contenu}
                        </Link>
                    ) : (
                        <div key={t.libelle} className={cls}>
                            {contenu}
                        </div>
                    );
                })}
            </div>
        </AppLayout>
    );
}
