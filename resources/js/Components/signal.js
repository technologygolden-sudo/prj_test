/** Signal sonore court pour le scan : aigu = accepté, double = ligne complète, grave = refusé. */
let contexte = null;

function bip(frequence, debut, duree) {
    try {
        contexte ??= new (window.AudioContext || window.webkitAudioContext)();
        const oscillateur = contexte.createOscillator();
        const gain = contexte.createGain();
        oscillateur.frequency.value = frequence;
        gain.gain.value = 0.08;
        oscillateur.connect(gain).connect(contexte.destination);
        oscillateur.start(contexte.currentTime + debut);
        oscillateur.stop(contexte.currentTime + debut + duree);
    } catch {
        // Pas de son disponible : le message à l'écran suffit.
    }
}

export const signal = {
    ok: () => bip(880, 0, 0.08),
    complet: () => {
        bip(880, 0, 0.1);
        bip(1320, 0.14, 0.16);
    },
    erreur: () => bip(220, 0, 0.25),
};
