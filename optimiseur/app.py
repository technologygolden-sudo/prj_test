"""Service HTTP d'optimisation, joignable uniquement depuis le réseau interne (dossier v2, section 5.4).

Il n'a aucun accès à la base : Laravel lui envoie les données et enregistre le résultat.
Chaque appel porte le jeton partagé OPTIMISEUR_JETON dans l'en-tête Authorization (Bearer).

Lancement : .venv\\Scripts\\python -m uvicorn app:app --host 127.0.0.1 --port 8090
"""

from __future__ import annotations

import hmac
import os

from fastapi import Depends, FastAPI, Header, HTTPException
from pydantic import BaseModel, Field, field_validator

from solveur import MODES, Kit, resoudre

JETON = os.environ.get("OPTIMISEUR_JETON", "")
LIMITE_SECONDES = float(os.environ.get("OPTIMISEUR_LIMITE_SECONDES", "4"))

app = FastAPI(title="Optimiseur de kits", docs_url=None, redoc_url=None, openapi_url=None)


def verifier_jeton(authorization: str = Header(default="")) -> None:
    attendu = f"Bearer {JETON}"
    if not JETON or not hmac.compare_digest(authorization.encode(), attendu.encode()):
        raise HTTPException(status_code=401, detail="Jeton invalide")


class KitEntree(BaseModel):
    id: str = Field(min_length=1, max_length=32)
    demande: int = Field(ge=0, le=1_000_000)
    rang: int = Field(default=1, ge=1, le=1000)
    composition: dict[str, int]

    @field_validator("composition")
    @classmethod
    def quantites_valides(cls, v: dict[str, int]) -> dict[str, int]:
        if not v or len(v) > 5000 or any(q < 1 or q > 100_000 for q in v.values()):
            raise ValueError("composition invalide")
        return v


class Demande(BaseModel):
    mode: str = "priorite"
    kits: list[KitEntree] = Field(min_length=1, max_length=200)
    stock: dict[str, int]

    @field_validator("mode")
    @classmethod
    def mode_connu(cls, v: str) -> str:
        if v not in MODES:
            raise ValueError("mode inconnu")
        return v

    @field_validator("stock")
    @classmethod
    def stock_valide(cls, v: dict[str, int]) -> dict[str, int]:
        if len(v) > 5000 or any(q < 0 or q > 100_000_000 for q in v.values()):
            raise ValueError("stock invalide")
        return v


@app.get("/sante")
def sante() -> dict[str, str]:
    return {"statut": "ok"}


@app.post("/resoudre", dependencies=[Depends(verifier_jeton)])
def resoudre_demande(demande: Demande) -> dict:
    kits = [Kit(k.id, k.demande, k.rang, k.composition) for k in demande.kits]
    r = resoudre(kits, demande.stock, demande.mode, LIMITE_SECONDES)
    return {"statut": r.statut, "optimalite": r.optimalite, "quantites": r.quantites, "duree_ms": r.duree_ms}
