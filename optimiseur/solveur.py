"""Moteur d'optimisation des kits (dossier de conception v2, section 5).

Programme linéaire en nombres entiers résolu par OR-Tools CP-SAT :
    max objectif  s.c.  somme_k a(i,k) * x(k) <= S(i)  pour tout article i,  0 <= x(k) <= D(k)

Modes :
- priorite  : résolution par paliers de rang (1 = le plus urgent) ; chaque palier figé avant le suivant ;
- equilibre : maximise le taux de service minimum (pour mille) des kits demandés, puis le total ;
- volume    : maximise le nombre total de kits.
"""

from __future__ import annotations

import time
from dataclasses import dataclass, field

from ortools.sat.python import cp_model

MODES = ("priorite", "equilibre", "volume")


@dataclass
class Kit:
    id: str
    demande: int
    rang: int
    composition: dict[str, int]


@dataclass
class Resultat:
    statut: str  # termine | echec
    optimalite: str  # optimal | realisable | non_resolu
    quantites: dict[str, int] = field(default_factory=dict)
    duree_ms: int = 0


def _statut(code: int) -> str | None:
    if code == cp_model.OPTIMAL:
        return "optimal"
    if code == cp_model.FEASIBLE:
        return "realisable"
    return None


def resoudre(kits: list[Kit], stock: dict[str, int], mode: str = "priorite", limite_secondes: float = 4.0) -> Resultat:
    if mode not in MODES:
        raise ValueError(f"Mode inconnu : {mode}")

    debut = time.monotonic()
    echeance = debut + limite_secondes
    modele = cp_model.CpModel()
    x = {k.id: modele.new_int_var(0, max(0, k.demande), f"x_{k.id}") for k in kits}

    articles = {article for k in kits for article in k.composition}
    for article in articles:
        termes = [k.composition[article] * x[k.id] for k in kits if k.composition.get(article, 0) > 0]
        modele.add(sum(termes) <= max(0, stock.get(article, 0)))

    optimalite = "optimal"

    def lancer(objectif) -> tuple[cp_model.CpSolver, str] | None:
        nonlocal optimalite
        restant = echeance - time.monotonic()
        if restant <= 0:
            return None
        modele.clear_objective()
        modele.maximize(objectif)
        solveur = cp_model.CpSolver()
        solveur.parameters.max_time_in_seconds = restant
        solveur.parameters.num_workers = 8
        statut = _statut(solveur.solve(modele))
        if statut is None:
            return None
        if statut == "realisable":
            optimalite = "realisable"
        return solveur, statut

    solveur: cp_model.CpSolver | None = None

    if mode == "priorite":
        for rang in sorted({k.rang for k in kits}):
            groupe = [x[k.id] for k in kits if k.rang == rang]
            sortie = lancer(sum(groupe))
            if sortie is None:
                break
            solveur, _ = sortie
            # Le palier est figé : les rangs suivants ne peuvent pas lui retirer d'articles.
            modele.add(sum(groupe) >= int(solveur.objective_value))
    elif mode == "equilibre":
        demandes = [k for k in kits if k.demande > 0]
        tau = modele.new_int_var(0, 1000, "tau")
        for k in demandes:
            modele.add(1000 * x[k.id] >= tau * k.demande)
        sortie = lancer(tau)
        if sortie is not None:
            solveur, _ = sortie
            modele.add(tau >= int(solveur.objective_value))
            sortie = lancer(sum(x.values()))
            if sortie is not None:
                solveur, _ = sortie
    else:
        sortie = lancer(sum(x.values()))
        if sortie is not None:
            solveur, _ = sortie

    duree = int((time.monotonic() - debut) * 1000)
    if solveur is None:
        return Resultat("echec", "non_resolu", {}, duree)

    return Resultat("termine", optimalite, {k.id: int(solveur.value(x[k.id])) for k in kits}, duree)
