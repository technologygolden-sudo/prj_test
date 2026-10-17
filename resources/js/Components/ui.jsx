import { Link, usePage } from '@inertiajs/react';

export function classes(...noms) {
    return noms.filter(Boolean).join(' ');
}

/** Masque un élément d'interface sans permission ; le serveur contrôle de toute façon (RG22). */
export function usePeut() {
    const { auth } = usePage().props;
    // Une liste de permissions = au moins l'une d'elles.
    return (permission) => (Array.isArray(permission) ? permission.some((p) => auth.permissions.includes(p)) : auth.permissions.includes(permission));
}

const styles = {
    primaire: 'bg-green-700 text-white hover:bg-green-800 focus-visible:outline-green-700',
    secondaire: 'bg-white text-slate-800 ring-1 ring-inset ring-slate-300 hover:bg-slate-50',
    danger: 'bg-white text-red-700 ring-1 ring-inset ring-red-300 hover:bg-red-50',
    discret: 'text-slate-600 hover:text-slate-900 hover:bg-slate-100',
};

export function Bouton({ variante = 'primaire', className, href, externe = false, ...props }) {
    const cls = classes(
        'inline-flex items-center justify-center gap-2 rounded-md px-3.5 py-2 text-sm font-medium transition',
        'focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
        styles[variante],
        className,
    );
    if (href && externe) {
        // Téléchargement (CSV, PDF) : lien classique, hors navigation Inertia.
        return <a href={href} className={cls} {...props} />;
    }
    if (href) {
        return <Link href={href} className={cls} {...props} />;
    }
    return <button type="button" className={cls} {...props} />;
}

export function Champ({ libelle, erreur, aide, htmlFor, children, className }) {
    return (
        <div className={className}>
            {libelle && (
                <label htmlFor={htmlFor} className="mb-1 block text-sm font-medium text-slate-700">
                    {libelle}
                </label>
            )}
            {children}
            {aide && !erreur && <p className="mt-1 text-xs text-slate-500">{aide}</p>}
            {erreur && <p className="mt-1 text-sm text-red-700">{erreur}</p>}
        </div>
    );
}

const saisie =
    'block w-full rounded-md border-0 bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 ' +
    'placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-green-600 disabled:bg-slate-100';

export function Saisie({ className, invalide, ...props }) {
    return <input className={classes(saisie, invalide && 'ring-red-400', className)} {...props} />;
}

/** Suggestions d'un champ texte, tirées des listes de valeurs du référentiel : <Saisie list="liste-motif_defaut" /> + <Suggestions liste="motif_defaut" />. */
export function Suggestions({ liste }) {
    const { listes } = usePage().props;
    return (
        <datalist id={`liste-${liste}`}>
            {(listes?.[liste] ?? []).map((v) => (
                <option key={v} value={v} />
            ))}
        </datalist>
    );
}

export function Liste({ className, invalide, children, ...props }) {
    return (
        <select className={classes(saisie, 'pr-8', invalide && 'ring-red-400', className)} {...props}>
            {children}
        </select>
    );
}

export function Carte({ titre, actions, children, className }) {
    return (
        <section className={classes('rounded-lg bg-white shadow-sm ring-1 ring-slate-200', className)}>
            {(titre || actions) && (
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-3">
                    {titre && <h2 className="text-base font-semibold text-slate-900">{titre}</h2>}
                    {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
                </div>
            )}
            <div className="p-5">{children}</div>
        </section>
    );
}

const pastilles = {
    vert: 'bg-green-50 text-green-800 ring-green-600/20',
    gris: 'bg-slate-100 text-slate-700 ring-slate-500/20',
    orange: 'bg-amber-50 text-amber-800 ring-amber-600/20',
    rouge: 'bg-red-50 text-red-700 ring-red-600/20',
};

export function Pastille({ couleur = 'gris', children }) {
    return (
        <span className={classes('inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', pastilles[couleur])}>
            {children}
        </span>
    );
}

const libellesStatut = {
    en_stock: ['En stock', 'vert'],
    assemble: ['Assemblé', 'gris'],
    epuise: ['Épuisé', 'gris'],
    expedie: ['Remis au technicien', 'gris'],
    en_transit: ['En transit', 'orange'],
    rebute: ['Rebuté', 'rouge'],
    retourne: ['Retourné', 'orange'],
    perdu: ['Perdu', 'rouge'],
    desassemble: ['Désassemblé', 'orange'],
};

export function Statut({ valeur }) {
    const [libelle, couleur] = libellesStatut[valeur] ?? [valeur, 'gris'];
    return <Pastille couleur={couleur}>{libelle}</Pastille>;
}

export function dateFr(valeur, heure = true) {
    if (!valeur) {
        return '—';
    }
    const d = new Date(String(valeur).replace(' ', 'T'));
    return heure ? d.toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : d.toLocaleDateString('fr-FR');
}

export function Tableau({ colonnes, children, vide }) {
    return (
        <div className="-mx-5 overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
                <thead>
                    <tr>
                        {colonnes.map((c) => (
                            <th
                                key={c.libelle}
                                scope="col"
                                className={classes('px-5 py-2.5 text-left font-semibold text-slate-700', c.droite && 'text-right')}
                            >
                                {c.libelle}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">{children}</tbody>
            </table>
            {vide && <p className="px-5 py-8 text-center text-sm text-slate-500">{vide}</p>}
        </div>
    );
}

export function Pagination({ liens }) {
    if (!liens || liens.length <= 3) {
        return null;
    }
    return (
        <nav className="mt-4 flex flex-wrap gap-1" aria-label="Pagination">
            {liens.map((lien, i) => (
                <Link
                    key={i}
                    href={lien.url ?? '#'}
                    preserveScroll
                    className={classes(
                        'rounded-md px-3 py-1.5 text-sm',
                        lien.active ? 'bg-green-700 text-white' : 'text-slate-700 hover:bg-slate-100',
                        !lien.url && 'pointer-events-none opacity-40',
                    )}
                >
                    {lien.label.replace('&laquo;', '«').replace('&raquo;', '»')}
                </Link>
            ))}
        </nav>
    );
}
