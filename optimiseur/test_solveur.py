"""Tests du solveur sur l'exemple 5.3 du dossier et sur les cas limites (section 5.4)."""

import os

os.environ.setdefault("OPTIMISEUR_JETON", "jeton-de-test")

from fastapi.testclient import TestClient  # noqa: E402

from app import app  # noqa: E402
from solveur import Kit, resoudre  # noqa: E402

KIT_A = Kit("A", 8, 1, {"onduleur": 1, "panneau": 4, "coffret": 1})
KIT_B = Kit("B", 5, 2, {"onduleur": 1, "batterie": 1, "panneau": 2})
STOCK = {"onduleur": 10, "panneau": 30, "coffret": 6, "batterie": 5}


def test_volume_donne_a5_b5():
    r = resoudre([KIT_A, KIT_B], STOCK, "volume")
    assert r.optimalite == "optimal"
    assert r.quantites == {"A": 5, "B": 5}


def test_priorite_sert_d_abord_le_rang_1():
    r = resoudre([KIT_A, KIT_B], STOCK, "priorite")
    assert r.quantites["A"] == 6  # limité par les coffrets
    assert r.quantites["B"] == 3  # le reste des panneaux : 30 - 24 = 6, soit 3 kits B


def test_equilibre_maximise_le_taux_minimum():
    r = resoudre([KIT_A, KIT_B], STOCK, "equilibre")
    a, b = r.quantites["A"], r.quantites["B"]
    assert min(a / 8, b / 5) >= 5 / 8
    assert a + b == 10


def test_cas_limites():
    assert resoudre([KIT_A], {}, "volume").quantites == {"A": 0}
    assert resoudre([Kit("A", 0, 1, {"onduleur": 1})], STOCK, "equilibre").quantites == {"A": 0}
    assert resoudre([KIT_A, Kit("C", 0, 1, {"onduleur": 1})], STOCK, "equilibre").quantites["A"] == 6


def test_api_exige_le_jeton():
    client = TestClient(app)
    corps = {"mode": "volume", "kits": [{"id": "A", "demande": 8, "composition": {"onduleur": 1}}], "stock": {"onduleur": 3}}
    assert client.post("/resoudre", json=corps).status_code == 401
    reponse = client.post("/resoudre", json=corps, headers={"Authorization": "Bearer jeton-de-test"})
    assert reponse.status_code == 200
    assert reponse.json()["quantites"] == {"A": 3}
