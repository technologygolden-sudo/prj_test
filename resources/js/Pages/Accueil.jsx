import { Link } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';
import { usePeut } from '../Components/ui';

export default function Accueil({ compteurs }) {
    const peut = usePeut();
    const tuiles = [
        { libelle: 'Articles', valeur: compteurs.articles, href: '/articles', permission: 'articles.voir' },
        { libelle: 'Types de kit', valeur: compteurs.types_kit, href: '/kits', permission: 'articles.voir' },
        { libelle: 'Versions en brouillon', valeur: compteurs.brouillons, href: '/kits', permission: 'articles.voir' },
        { libelle: 'Unités en stock', valeur: compteurs.unites_en_stock, href: null, permission: null },
    ];

    return (
        <AppLayout titre="Accueil">
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
            <p className="mt-6 text-sm text-slate-500">
                Réception, assemblage, étiquettes et simulation arrivent dans les prochaines étapes du plan.
            </p>
        </AppLayout>
    );
}
