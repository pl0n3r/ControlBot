"""ControlBot #1363 — synthetic owner-only panel emergency-stop contract.

This is an offline executable model in tests, NOT wired into PHP or Identity Center.
No real session, token, database, deployment, or remote service is modified.
"""
from __future__ import annotations

import re
import unittest


class SyntheticIdentityCenter:
    """Fake sessions and grants, never an identity provider or remote client."""

    def __init__(self) -> None:
        self.sessions = {"owner-session": "owner", "staff-session": "staff"}
        self.grants = {"fixture-staff-access", "fixture-owner-access"}
        self.fail_revocation = False
        self.calls = 0

    def revoke_all(self) -> tuple[int, int]:
        self.calls += 1
        if self.fail_revocation:
            raise RuntimeError("synthetic identity center unavailable")
        counts = (len(self.sessions), len(self.grants))
        self.sessions.clear()
        self.grants.clear()
        return counts

    def create_session_after_reauth(self, who: str, session: str) -> None:
        self.sessions[session] = who


class PanelEmergencyStopFixture:
    """Pure test fixture specifying fail-closed behavior for a future PHP adapter."""

    def __init__(self, identity: SyntheticIdentityCenter) -> None:
        self.identity = identity
        self.mode = "normal"
        self.revocation_pending = False
        self.audit: list[dict] = []
        self.last_at = 0

    @staticmethod
    def owner(actor: dict) -> bool:
        return actor.get("login") == "owner" and actor.get("mfa_verified") is True

    @staticmethod
    def valid_reason(reason: str) -> bool:
        return isinstance(reason, str) and bool(re.fullmatch(r"[a-z][a-z0-9_]{2,63}", reason))

    def _validate(self, actor: dict, reason: str, at: int) -> None:
        if not isinstance(actor, dict) or not self.owner(actor):
            raise PermissionError("owner and verified MFA required")
        if not self.valid_reason(reason):
            raise ValueError("canonical incident reason required")
        if type(at) is not int or at <= self.last_at:
            raise ValueError("timestamp must move forward")

    def stop(self, actor: dict, reason: str, at: int) -> dict:
        self._validate(actor, reason, at)
        if self.mode == "read_only":
            return {"status": "already_stopped", "pending": self.revocation_pending}
        # Deny writes BEFORE attempting any external identity action.
        self.mode = "read_only"
        self.revocation_pending = True
        self.last_at = at
        return self._revoke_and_audit("stop", reason, at)

    def retry_revocation(self, actor: dict, reason: str, at: int) -> dict:
        self._validate(actor, reason, at)
        if self.mode != "read_only" or not self.revocation_pending:
            raise ValueError("no outstanding revocation to retry")
        self.last_at = at
        return self._revoke_and_audit("retry_revoke", reason, at)

    def _revoke_and_audit(self, action: str, reason: str, at: int) -> dict:
        try:
            sessions, grants = self.identity.revoke_all()
            status = "revoked"
            self.revocation_pending = False
        except RuntimeError:
            sessions, grants = 0, 0
            status = "revocation_pending"
        receipt = {
            "action": action,
            "actor": "owner",
            "at": at,
            "reason_code": reason,
            "status": status,
            "revoked_sessions": sessions,
            "revoked_grants": grants,
        }
        self.audit.append(receipt)
        return dict(receipt)

    def authorize(self, operation: str, session: str) -> bool:
        if operation not in ("read", "write"):
            return False
        # A failed revocation is containment-only, not a successful shutdown.
        if self.revocation_pending:
            return False
        if session not in self.identity.sessions:
            return False
        return operation == "read" or self.mode == "normal"

    def resume(self, actor: dict, reason: str, at: int, *, confirmed: bool) -> dict:
        self._validate(actor, reason, at)
        if not confirmed or self.mode != "read_only" or self.revocation_pending:
            raise PermissionError("explicit owner recovery and completed revocation required")
        self.mode = "normal"
        self.last_at = at
        receipt = {"action": "resume", "actor": "owner", "at": at,
                   "reason_code": reason, "status": "resumed"}
        self.audit.append(receipt)
        return dict(receipt)


class PanelKillSwitchTests(unittest.TestCase):
    OWNER = {"login": "owner", "mfa_verified": True}
    STAFF = {"login": "staff", "mfa_verified": True}
    NO_MFA = {"login": "owner", "mfa_verified": False}

    def setUp(self) -> None:
        self.identity = SyntheticIdentityCenter()
        self.switch = PanelEmergencyStopFixture(self.identity)

    def test_criterio_principal(self) -> None:
        self.assertTrue(self.switch.authorize("write", "owner-session"))
        self.assertTrue(self.switch.authorize("read", "staff-session"))
        stopped = self.switch.stop(self.OWNER, "incident_1363", 100)
        self.assertEqual(stopped["status"], "revoked")
        self.assertEqual((stopped["revoked_sessions"], stopped["revoked_grants"]), (2, 2))
        self.assertEqual(self.switch.mode, "read_only")
        self.assertFalse(self.switch.authorize("write", "owner-session"))
        self.assertFalse(self.switch.authorize("read", "staff-session"))
        self.identity.create_session_after_reauth("owner", "fresh-owner-session")
        self.assertTrue(self.switch.authorize("read", "fresh-owner-session"))
        self.assertFalse(self.switch.authorize("write", "fresh-owner-session"))
        with self.assertRaises(PermissionError):
            self.switch.resume(self.OWNER, "incident_1363_resolved", 101, confirmed=False)
        recovered = self.switch.resume(self.OWNER, "incident_1363_resolved", 102, confirmed=True)
        self.assertEqual(recovered["status"], "resumed")
        self.assertTrue(self.switch.authorize("write", "fresh-owner-session"))
        self.assertFalse(self.switch.authorize("write", "staff-session"))
        self.assertEqual([row["action"] for row in self.switch.audit], ["stop", "resume"])

    def test_non_owner_or_unverified_mfa_cannot_stop_or_resume(self) -> None:
        for actor in (self.STAFF, self.NO_MFA, {}, {"login": "owner"}):
            with self.subTest(actor=actor), self.assertRaises(PermissionError):
                self.switch.stop(actor, "incident_1363", 100)
        self.assertEqual(self.switch.mode, "normal")
        self.assertEqual(self.switch.audit, [])
        self.switch.stop(self.OWNER, "incident_1363", 100)
        for actor in (self.STAFF, self.NO_MFA):
            with self.assertRaises(PermissionError):
                self.switch.resume(actor, "resolved_1363", 101, confirmed=True)
        self.assertEqual(self.switch.mode, "read_only")

    def test_recovery_does_not_restore_revoked_sessions_or_grants(self) -> None:
        self.switch.stop(self.OWNER, "incident_1363", 100)
        self.switch.resume(self.OWNER, "resolved_1363", 101, confirmed=True)
        self.assertEqual(self.identity.sessions, {})
        self.assertEqual(self.identity.grants, set())

    def test_identity_failure_leaves_maintenance_closed_and_audited(self) -> None:
        self.identity.fail_revocation = True
        receipt = self.switch.stop(self.OWNER, "incident_1363", 100)
        self.assertEqual(receipt["status"], "revocation_pending")
        self.assertEqual(self.switch.mode, "read_only")
        self.assertFalse(self.switch.authorize("read", "owner-session"))
        self.assertFalse(self.switch.authorize("write", "staff-session"))
        with self.assertRaises(PermissionError):
            self.switch.resume(self.OWNER, "resolved_1363", 101, confirmed=True)
        self.identity.fail_revocation = False
        retry = self.switch.retry_revocation(self.OWNER, "incident_1363", 102)
        self.assertEqual(retry["status"], "revoked")
        self.assertEqual(len(self.identity.sessions), 0)
        self.switch.resume(self.OWNER, "resolved_1363", 103, confirmed=True)
        self.assertEqual([r["status"] for r in self.switch.audit],
                         ["revocation_pending", "revoked", "resumed"])

    def test_idempotent_stop_does_not_repeat_revocations(self) -> None:
        self.switch.stop(self.OWNER, "incident_1363", 100)
        self.assertEqual(self.switch.stop(self.OWNER, "incident_1363", 101)["status"], "already_stopped")
        self.assertEqual(self.identity.calls, 1)
        self.assertEqual(len(self.switch.audit), 1)

    def test_invalid_reason_and_replayed_clock_cannot_mutate(self) -> None:
        for reason in ("", " ", "Contains PII", "secret=abc", "a" * 200, None):
            with self.subTest(reason=reason), self.assertRaises(ValueError):
                self.switch.stop(self.OWNER, reason, 100)
        self.assertEqual(self.switch.audit, [])
        self.switch.stop(self.OWNER, "incident_1363", 100)
        with self.assertRaises(ValueError):
            self.switch.resume(self.OWNER, "resolved_1363", 100, confirmed=True)
        self.assertEqual(self.switch.mode, "read_only")

    def test_unknown_operation_and_stale_session_denied(self) -> None:
        self.assertFalse(self.switch.authorize("publish_external", "owner-session"))
        self.assertFalse(self.switch.authorize("write", "not-real"))
        self.assertFalse(self.switch.authorize("read", "not-real"))

    def test_audit_minimizes_data_and_identity_never_sees_credentials(self) -> None:
        self.switch.stop(self.OWNER, "incident_1363", 100)
        fields = set(self.switch.audit[0])
        self.assertEqual(fields, {"action", "actor", "at", "reason_code", "status",
                                  "revoked_sessions", "revoked_grants"})
        self.assertNotIn("staff-session", str(self.switch.audit))
        self.assertNotIn("token", str(self.switch.audit).lower())

    def test_documented_boundary_is_not_deployed(self) -> None:
        from pathlib import Path
        doc = (Path(__file__).resolve().parents[1] / "docs" / "panel-kill-switch.md").read_text()
        for marker in ("NO CONECTADO", "Identity Center", "Factory #767",
                       "PanelKillSwitchTests::test_criterio_principal", "D-069"):
            self.assertIn(marker, doc)


if __name__ == "__main__":
    unittest.main()
