"""Regresión ejecutable del contrato de tarjeta de cuotas #1360.

Ejecuta únicamente el componente PHP documental con fakes; no conecta
proveedores ni afirma que el panel real tenga un adaptador de cuotas.
"""
import json
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "quota-panel.md"


def render(measurements, threshold=0.8):
    text = DOC.read_text(encoding="utf-8")
    blocks = re.findall(r"(?ms)^~~~php\n(.*?)^~~~\s*$", text)
    if len(blocks) != 1:
        raise AssertionError("debe haber un único componente PHP documentado")
    runner = (
        blocks[0] + "\n"
        + '$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);'
        + ' $t = $input["threshold"]; echo quota_panel_render($input["measurements"], is_int($t) || is_float($t) ? (float)$t : NAN);'
    )
    proc = subprocess.run(
        ["php", "-r", runner],
        input=json.dumps({"measurements": measurements, "threshold": threshold}),
        capture_output=True, text=True, cwd=ROOT, timeout=20,
        check=True,
    )
    return proc.stdout


def row(html, source):
    match = re.search(
        r'<tr data-source="' + re.escape(source) + r'" data-warning="(yes|no)">(.*?)</tr>',
        html, re.DOTALL,
    )
    if not match:
        raise AssertionError("fuente ausente: " + source)
    return match.group(1), match.group(2)


class QuotaPanelNonblockingTests(unittest.TestCase):
    def test_criterio_principal(self):
        html = render({
            "github": {"used": 81, "limit": 100,
                       "measured_at": "2026-10-31T18:04:00Z"},
            "hostinger": {"used": 0, "limit": 20,
                          "measured_at": "2026-10-31T12:00:00Z"},
            "observer": {},
            "cron": {"used": 11, "limit": 10,
                     "measured_at": "2026-10-31T17:00:00Z"},
        })
        self.assertIn("Cuotas (solo información)", html)
        self.assertEqual(len(re.findall(r'<tr data-source="', html)), 4)
        self.assertEqual(
            re.findall(r'<tr data-source="([^"]+)"', html),
            ["github", "hostinger", "observer", "cron"],
        )
        self.assertEqual(row(html, "github")[0], "yes")
        self.assertIn("81</td><td>100", row(html, "github")[1])
        self.assertIn("31/10/2026 1:04 PM", row(html, "github")[1])
        self.assertEqual(row(html, "hostinger")[0], "no")
        self.assertIn("0</td><td>20", row(html, "hostinger")[1])
        self.assertEqual(row(html, "observer")[0], "no")
        self.assertEqual(row(html, "observer")[1].count("UNKNOWN"), 3)
        self.assertEqual(row(html, "cron")[0], "yes")
        self.assertNotIn("<button", html)
        self.assertNotIn("<form", html)

    def test_umbral_configurable_supera_pero_no_iguala(self):
        source = {"github": {"used": 75, "limit": 100}}
        self.assertEqual(row(render(source, 0.75), "github")[0], "no")
        self.assertEqual(row(render(source, 0.70), "github")[0], "yes")
        self.assertEqual(row(render(source, 0.90), "github")[0], "no")
        self.assertEqual(row(render(source, -1), "github")[0], "no")
        self.assertEqual(row(render(source, 2), "github")[0], "no")
        self.assertEqual(row(render(source, "invalid"), "github")[0], "no")

    def test_unknown_no_se_convierte_en_cero_ni_en_bloqueo(self):
        invalid = {
            "github": {"used": True, "limit": 1, "measured_at": "ayer"},
            "hostinger": {"used": 2, "limit": 0},
            "observer": {"used": -1, "limit": 10},
            "cron": {"used": 1.5, "limit": 2},
        }
        html = render(invalid)
        self.assertEqual(len(re.findall('data-warning="yes"', html)), 0)
        self.assertEqual(row(html, "github")[1].count("UNKNOWN"), 2)
        self.assertIn("<td>2</td><td>UNKNOWN</td>", row(html, "hostinger")[1])
        self.assertIn("<td>UNKNOWN</td><td>10</td>", row(html, "observer")[1])
        self.assertIn("<td>UNKNOWN</td><td>2</td>", row(html, "cron")[1])
        self.assertNotIn("PAUSE", html)
        self.assertNotIn("BLOCKED", html)
        self.assertNotIn("denegada", html.lower())

    def test_fecha_invalida_y_12_horas(self):
        html = render({
            "github": {"used": 0, "limit": 1,
                       "measured_at": "2026-10-31T05:00:00Z"},
            "cron": {"used": 0, "limit": 1,
                     "measured_at": "2026-02-30T08:00:00Z"},
        })
        self.assertIn("31/10/2026 12:00 AM", row(html, "github")[1])
        self.assertIn("UNKNOWN", row(html, "cron")[1])

    def test_datos_no_confiables_no_se_reflejan_en_html(self):
        hostile = '<script>alert("x")</script>'
        html = render({
            "github": {"used": hostile, "limit": hostile,
                       "measured_at": hostile},
            "not-a-provider": {"used": 100, "limit": 1},
        })
        self.assertEqual(len(re.findall(r'<tr data-source="', html)), 4)
        self.assertNotIn(hostile, html)
        self.assertNotIn("<script", html)
        self.assertEqual(row(html, "github")[1].count("UNKNOWN"), 3)

    def test_documentacion_explica_no_activacion(self):
        text = DOC.read_text(encoding="utf-8")
        for phrase in ("America/Bogota", "UNKNOWN", "solo lectura",
                       "no un endpoint", "integración futura",
                       "CI Factory v1 / Validar"):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, text)


if __name__ == "__main__":
    unittest.main()
