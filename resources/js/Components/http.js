/** Appels JSON (contrôles de scan) avec le jeton CSRF de Laravel. */
function jetonXsrf() {
    const cookie = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
}

export async function postJson(url, donnees) {
    const reponse = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': jetonXsrf() },
        body: JSON.stringify(donnees),
    });
    const corps = await reponse.json().catch(() => ({}));
    if (!reponse.ok) {
        const message = corps.message ?? Object.values(corps.errors ?? {})[0]?.[0] ?? `Erreur ${reponse.status}`;
        throw new Error(message);
    }
    return corps;
}

/** Soumet un formulaire classique dans un nouvel onglet (PDF d'étiquettes). */
export function soumettreDansOnglet(url, donnees) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = url;
    form.target = '_blank';
    const champs = { _token: document.querySelector('meta[name="csrf-token"]')?.content ?? '', ...aplatir(donnees) };
    for (const [nom, valeur] of Object.entries(champs)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = nom;
        input.value = valeur ?? '';
        form.appendChild(input);
    }
    document.body.appendChild(form);
    form.submit();
    form.remove();
}

function aplatir(objet, prefixe = '') {
    return Object.entries(objet).reduce((acc, [cle, valeur]) => {
        const nom = prefixe ? `${prefixe}[${cle}]` : cle;
        if (valeur !== null && typeof valeur === 'object') {
            Object.assign(acc, aplatir(valeur, nom));
        } else {
            acc[nom] = valeur;
        }
        return acc;
    }, {});
}
