#!/usr/bin/env python3
"""Generates template.pdf — a one-page SEPA-mandate-shaped PDF with named
AcroForm text fields, written uncompressed with a classic xref table so the
PHP-side parsers (FPDM, FPDI free, regex field enumeration) get the friendliest
possible input. This mirrors the export constraints the real club template will
have to satisfy (clubbar#777 spike).

Run: python3 tools/make_template.py   (writes ../template.pdf)
"""
import os
from reportlab.lib.pagesizes import A4
from reportlab.lib.colors import HexColor, black
from reportlab.pdfgen import canvas

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, "..", "template.pdf")

W, H = A4  # 595 x 842 pt
MARGIN = 50
INK = HexColor("#1a1a2e")
GRAY = HexColor("#666666")
FIELD_BG = HexColor("#f4f6fa")

c = canvas.Canvas(OUT, pagesize=A4, pageCompression=0)
c.setTitle("SEPA-Lastschriftmandat (Spike-Vorlage)")
form = c.acroForm

y = H - 70


def label(x, yy, text, size=8, color=GRAY):
    c.setFont("Helvetica", size)
    c.setFillColor(color)
    c.drawString(x, yy, text)
    c.setFillColor(black)


def field(name, x, yy, w, h=16, tooltip=""):
    form.textfield(
        name=name, tooltip=tooltip or name,
        x=x, y=yy, width=w, height=h,
        fontName="Helvetica", fontSize=10,
        borderWidth=0.5, borderColor=GRAY, fillColor=FIELD_BG,
        textColor=INK, relative=False,
    )
    # Writing line as PAGE CONTENT: field borders are annotations and vanish
    # when the page is flattened — the printed line must survive (the admin
    # variant leaves the IBAN blank for handwriting).
    c.setLineWidth(0.6)
    c.setStrokeColor(GRAY)
    c.line(x, yy - 1.5, x + w, yy - 1.5)
    c.setStrokeColor(black)


# --- Header -----------------------------------------------------------------
c.setFont("Helvetica-Bold", 15)
c.setFillColor(INK)
c.drawString(MARGIN, y, "SEPA-Lastschriftmandat")
c.setFont("Helvetica", 9)
c.setFillColor(GRAY)
c.drawString(MARGIN, y - 14, "Wiederkehrende Zahlungen · Spike-Vorlage (clubbar#777) — KEIN gültiges Mandat")
c.setFillColor(black)
y -= 45

# --- Creditor block (filled from sepa_config) -------------------------------
label(MARGIN, y, "Zahlungsempfänger (Gläubiger)")
field("glaeubiger_name", MARGIN, y - 20, 280)
label(MARGIN, y - 30, "Name des Vereins")
field("glaeubiger_id", MARGIN + 300, y - 20, 200)
label(MARGIN + 300, y - 30, "Gläubiger-Identifikationsnummer")
y -= 60

field("mandatsreferenz", MARGIN, y - 20, 280)
label(MARGIN, y - 30, "Mandatsreferenz")
y -= 60

# --- Member block ------------------------------------------------------------
label(MARGIN, y, "Zahlungspflichtiger (Kontoinhaber)")
field("vorname", MARGIN, y - 20, 240)
label(MARGIN, y - 30, "Vorname")
field("nachname", MARGIN + 260, y - 20, 240)
label(MARGIN + 260, y - 30, "Nachname")
y -= 60

field("iban", MARGIN, y - 20, 340, tooltip="IBAN (leer auf dem Admin-Ausdruck)")
label(MARGIN, y - 30, "IBAN — bei Ausdruck durch den Verein bitte handschriftlich eintragen")
field("iban_last4", MARGIN + 360, y - 20, 140, tooltip="Kontrollhinweis ****XXXX")
label(MARGIN + 360, y - 30, "Kontroll-Hinweis (endet auf)")
y -= 70

# --- Mandate text (static) ---------------------------------------------------
c.setFont("Helvetica", 9)
c.setFillColor(INK)
text = c.beginText(MARGIN, y)
for line in [
    "Ich ermächtige den oben genannten Verein, wiederkehrende Zahlungen aus der Nutzung der bargeldlosen",
    "Vereinsbar von meinem Konto mittels Lastschrift einzuziehen. Zugleich weise ich mein Kreditinstitut an,",
    "die vom Verein auf mein Konto gezogenen Lastschriften einzulösen.",
    "",
    "Hinweis: Ich kann innerhalb von acht Wochen, beginnend mit dem Belastungsdatum, die Erstattung des",
    "belasteten Betrages verlangen. Es gelten dabei die mit meinem Kreditinstitut vereinbarten Bedingungen.",
    "Dieses Mandat gilt nur für die Vereinsbar; ein für Mitgliedsbeiträge erteiltes Mandat bleibt unberührt.",
]:
    text.textLine(line)
c.drawText(text)
c.setFillColor(black)
y -= 110

# --- Date + signatures -------------------------------------------------------
field("datum_ort", MARGIN, y - 20, 220, tooltip="Ort, Datum")
label(MARGIN, y - 30, "Ort, Datum")
y -= 70

c.setLineWidth(0.7)
c.line(MARGIN, y, MARGIN + 220, y)
label(MARGIN, y - 10, "Unterschrift Kontoinhaber")
c.line(MARGIN + 280, y, MARGIN + 500, y)
label(MARGIN + 280, y - 10, "Unterschrift Mitglied (falls abweichend)")

# --- Footer ------------------------------------------------------------------
c.setFont("Helvetica", 7)
c.setFillColor(GRAY)
c.drawString(MARGIN, 40, "Spike-Vorlage für clubbar#777 — uncompressed, classic xref, AcroForm-Textfelder. Nach dem Test löschen.")

c.showPage()
c.save()
print(f"written: {os.path.normpath(OUT)}")
