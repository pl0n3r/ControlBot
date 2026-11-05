#!/usr/bin/env python3
"""Contrato sintético del caller ControlBot #1460. No efectúa CAS real."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/coordinacion.yml"
PREFIX = "/recuperar-lock-v3-activa "
SHA = "a" * 40
LEASE = "add65c2f-9eac-4752-8e9a-dba233dd1bda"
VALID = PREFIX + SHA + " " + LEASE
EXACT_COMMANDS = ("/tomar", "/liberar-forzado", "/adoptar-contrato-huerfana")
PREFIX_COMMANDS = ("/liberar ", "/transferir ", "/migrar-contrato ", "/renovar-contrato ")
GRAMMAR = re.compile(
    r"/recuperar-lock-v3-activa [0-9a-f]{40} "
    r"[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\Z"
)
EXPECTED = " ".join((
    "github.event_name == 'issue_comment' &&",
    "github.event.issue.pull_request == null &&",
    "github.event.sender.login == github.event.comment.user.login &&",
    "(",
    "github.event.comment.body == '/tomar' ||",
    "github.event.comment.body == '/liberar-forzado' ||",
    "github.event.comment.body == '/adoptar-contrato-huerfana' ||",
    "startsWith(github.event.comment.body, '/liberar ') ||",
    "startsWith(github.event.comment.body, '/transferir ') ||",
    "startsWith(github.event.comment.body, '/migrar-contrato ') ||",
    "startsWith(github.event.comment.body, '/renovar-contrato ') ||",
    "(",
    "github.event.comment.author_association == 'OWNER' &&",
    "startsWith(github.event.comment.body, '/recuperar-lock-v3-activa ')",
    ")", ")",
))


def routed(body: str, *, event: str = "issue_comment", is_issue: bool = True,
           sender: str = "pl0n3r", author: str = "pl0n3r",
           association: str = "OWNER") -> bool:
    """Modelo puro de la guarda EXACTA comprobada en test_guard."""
    return (event == "issue_comment" and is_issue and sender == author and (
        body in EXACT_COMMANDS
        or any(body.startswith(p) for p in PREFIX_COMMANDS)
        or (association == "OWNER" and body.startswith(PREFIX))
    ))


class OwnerCasCallerTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")
        cls.job = cls.workflow.split("  comentario:\n", 1)[1].split("\n  etiqueta:\n", 1)[0]
        cls.guard = " ".join(cls.job.split("    if: >-\n", 1)[1]
                             .split("    permissions:\n", 1)[0].split())

    def test_guard_y_comandos_anteriores_intactos(self):
        self.assertEqual(self.guard, EXPECTED)
        self.assertEqual(self.workflow.count(PREFIX), 1)
        self.assertIn("issue_comment:\n    types: [created, edited]", self.workflow)
        self.assertNotIn("pull_request_target:", self.workflow)
        for body in EXACT_COMMANDS + tuple(p + "x" for p in PREFIX_COMMANDS):
            with self.subTest(body=body):
                self.assertTrue(routed(body, association="CONTRIBUTOR"))

    def test_serializacion_y_reusable_sin_escalar_permiso(self):
        self.assertIn(
            "group: coordinacion-${ github.event.issue.number || "
            "github.event.pull_request.head.ref || github.run_id }}",
            self.workflow,
        )
        self.assertIn("cancel-in-progress: false", self.workflow)
        self.assertIn("uses: pl0n3r/factory/.github/workflows/coordinacion.yml@v1", self.job)
        for fragment in (
            "      contents: write", "      issues: write",
            "      pull-requests: write", "      checks: write",
            "operation: comment",
            "actor: ${ github.event.comment.user.login }}",
            "association: ${ github.event.comment.author_association }}",
            "body: ${ github.event.comment.body }}",
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, self.job)

    def test_owner_autentico_en_issue_aceptado(self):
        self.assertTrue(routed(VALID))
        self.assertIsNotNone(GRAMMAR.fullmatch(VALID))

    def test_denegar_pr_fork_actor_o_asociacion_no_owner(self):
        scenarios = (
            {"is_issue": False}, {"event": "pull_request"},
            {"event": "workflow_dispatch"}, {"event": "issues"},
            {"sender": "attacker"}, {"author": "attacker"},
            {"association": "NONE"}, {"association": "CONTRIBUTOR"},
            {"association": "COLLABORATOR"}, {"association": "MEMBER"},
        )
        for ctx in scenarios:
            with self.subTest(ctx=ctx):
                self.assertFalse(routed(VALID, **ctx))

    def test_prefijo_no_coincidente_o_texto_narrativo(self):
        for body in (
            "/recuperar-lock-v3-activa", " " + VALID,
            "/recuperar-lock-v3-activado " + SHA,
            "/recuperar-lock-huerfano " + SHA,
            "Por favor " + VALID, "/tomar #1460",
        ):
            with self.subTest(body=body):
                self.assertFalse(routed(body))

    def test_gramatica_rechaza_malformados_sin_ejecutar_cas(self):
        # El caller sólo clasifica el prefijo, nunca autoriza recuperación;
        # SHA40, UUID, firma, lease y CAS los comprueba Factory protegido.
        for body in (
            PREFIX + SHA, PREFIX + "not-sha " + LEASE,
            PREFIX + SHA + " uuid-malformado",
            VALID + " extra", VALID + "\n",
            PREFIX + SHA.upper() + " " + LEASE,
        ):
            with self.subTest(body=body):
                self.assertTrue(routed(body))
                self.assertIsNone(GRAMMAR.fullmatch(body))


if __name__ == "__main__":
    unittest.main()
