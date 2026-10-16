import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { classes, usePeut } from '../Components/ui';

const navigation = [
    { titre: 'Accueil', href: '/', permission: null },
    { section: 'Atelier et magasin' },
    { titre: 'Réceptions', href: '/receptions', permission: 'reception.creer' },
    { titre: 'Assemblage', href: '/assemblage', permission: 'assemblage.realiser' },
    { titre: 'Sorties', href: '/sorties', permission: 'stock.sortir' },
    { titre: 'Stock', href: '/stock', permission: 'articles.voir' },
    { titre: 'Kits assemblés', href: '/kits-assembles', permission: 'articles.voir' },
    { section: 'Référentiel' },
    { titre: 'Articles', href: '/articles', permission: 'articles.voir' },
    { titre: 'Types de kit', href: '/kits', permission: 'articles.voir' },
    { titre: 'Catégories', href: '/categories', permission: 'articles.gerer' },
    { titre: 'Fabricants', href: '/fabricants', permission: 'articles.gerer' },
    { titre: 'Import Excel', href: '/import', permission: 'kits.gerer' },
    { section: 'Planification' },
    { titre: 'Capacité', href: '/capacite', permission: 'capacite.consulter' },
    { titre: 'Simulations', href: '/simulations', permission: 'simulation.lancer' },
    { section: 'Administration' },
    { titre: 'Utilisateurs', href: '/admin/utilisateurs', permission: 'utilisateurs.gerer' },
    { titre: 'Rôles et permissions', href: '/admin/roles', permission: 'roles.gerer' },
    { titre: 'Paramètres', href: '/admin/parametres', permission: 'parametres.gerer' },
    { titre: 'Journal d’audit', href: '/admin/audit', permission: 'audit.consulter' },
];

function RechercheRapide() {
    const [q, setQ] = useState('');
    return (
        <form
            role="search"
            className="hidden sm:block"
            onSubmit={(e) => {
                e.preventDefault();
                if (q.trim()) {
                    router.get('/recherche', { q: q.trim() });
                    setQ('');
                }
            }}
        >
            <input
                type="search"
                value={q}
                onChange={(e) => setQ(e.target.value)}
                placeholder="Scanner ou saisir un UID, n° de série…"
                aria-label="Recherche par scan"
                className="w-72 rounded-md border-0 bg-slate-100 px-3 py-1.5 text-sm ring-1 ring-slate-200 focus:bg-white focus:ring-2 focus:ring-green-600"
            />
        </form>
    );
}

function Messages() {
    const { flash, errors } = usePage().props;
    const [visible, setVisible] = useState(true);
    useEffect(() => setVisible(true), [flash.succes, flash.alerte]);

    const message = flash.alerte ?? flash.succes ?? (Object.keys(errors).length > 0 ? 'Certains champs sont à corriger.' : null);
    if (!message || !visible) {
        return null;
    }
    const alerte = Boolean(flash.alerte) || (!flash.succes && Object.keys(errors).length > 0);

    return (
        <div
            role={alerte ? 'alert' : 'status'}
            className={classes(
                'mb-5 flex items-start justify-between gap-4 rounded-md px-4 py-3 text-sm ring-1',
                alerte ? 'bg-amber-50 text-amber-900 ring-amber-200' : 'bg-green-50 text-green-900 ring-green-200',
            )}
        >
            <span>{message}</span>
            <button type="button" onClick={() => setVisible(false)} className="font-medium opacity-70 hover:opacity-100" aria-label="Fermer">
                ×
            </button>
        </div>
    );
}

export default function AppLayout({ titre, actions, children }) {
    const { auth, app } = usePage().props;
    const peut = usePeut();
    const chemin = usePage().url.split('?')[0];
    const [menuOuvert, setMenuOuvert] = useState(false);

    const liens = navigation.filter((item, i, tous) => {
        if (item.section) {
            const suivants = tous.slice(i + 1);
            const fin = suivants.findIndex((s) => s.section);
            return (fin === -1 ? suivants : suivants.slice(0, fin)).some((s) => !s.permission || peut(s.permission));
        }
        return !item.permission || peut(item.permission);
    });

    const actif = (href) => (href === '/' ? chemin === '/' : chemin.startsWith(href));

    const menu = (
        <nav className="flex flex-col gap-0.5 p-3">
            {liens.map((item) =>
                item.section ? (
                    <p key={item.section} className="mt-4 mb-1 px-3 text-xs font-semibold tracking-wide text-green-200/70 uppercase">
                        {item.section}
                    </p>
                ) : (
                    <Link
                        key={item.href}
                        href={item.href}
                        onClick={() => setMenuOuvert(false)}
                        className={classes(
                            'rounded-md px-3 py-2 text-sm font-medium',
                            actif(item.href) ? 'bg-white/15 text-white' : 'text-green-50/90 hover:bg-white/10 hover:text-white',
                        )}
                    >
                        {item.titre}
                    </Link>
                ),
            )}
        </nav>
    );

    return (
        <div className="min-h-screen lg:flex">
            <Head title={titre} />
            <aside className="hidden w-60 shrink-0 bg-green-800 lg:flex lg:flex-col">
                <div className="flex h-14 items-center border-b border-white/10 px-5">
                    <Link href="/" className="text-base font-semibold text-white">
                        {app.nom}
                    </Link>
                </div>
                <div className="flex-1 overflow-y-auto">{menu}</div>
            </aside>

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex h-14 items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 lg:px-8">
                    <button
                        type="button"
                        className="rounded-md px-2 py-1 text-sm font-medium text-slate-700 ring-1 ring-slate-300 lg:hidden"
                        onClick={() => setMenuOuvert(!menuOuvert)}
                        aria-expanded={menuOuvert}
                    >
                        Menu
                    </button>
                    <span className="truncate text-sm text-slate-500 lg:hidden">{app.nom}</span>
                    {peut('recherche.scanner') && <RechercheRapide />}
                    <div className="ml-auto flex items-center gap-3 text-sm">
                        <Link href="/compte/securite" className="text-slate-700 hover:text-slate-900">
                            {auth.user.name}
                        </Link>
                        <button type="button" onClick={() => router.post('/logout')} className="text-slate-500 hover:text-slate-900">
                            Déconnexion
                        </button>
                    </div>
                </header>
                {menuOuvert && <div className="bg-green-800 lg:hidden">{menu}</div>}

                <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-6 lg:px-8">
                    <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                        <h1 className="text-xl font-semibold text-slate-900">{titre}</h1>
                        {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
                    </div>
                    <Messages />
                    {children}
                </main>
            </div>
        </div>
    );
}
