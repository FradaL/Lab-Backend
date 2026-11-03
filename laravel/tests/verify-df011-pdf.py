"""Check synthetic DF-011 PDFs using Poppler text and real column coordinates.

Usage: python3 tests/verify-df011-pdf.py /tmp/df011-artifacts/multipage.pdf
The fourteen table-*.pdf/json fixtures are required alongside the multipage PDF.
"""
import io
from datetime import datetime
import json
import pathlib
import re
import subprocess
import sys
import xml.etree.ElementTree as ET

from PIL import Image

NS = {"h": "http://www.w3.org/1999/xhtml"}
MM = 72 / 25.4
EDGES = [15 * MM, 68 * MM, 117 * MM, 140 * MM, 195 * MM]


def compact(value):
    return re.sub(r"\s+", "", value)


def inspect(pdf, theme="neutral", validated_exams=()):
    info = subprocess.check_output(["pdfinfo", str(pdf)], text=True)
    assert "612 x 792 pts (letter)" in info, (pdf.name, "Letter paper")
    text = subprocess.check_output(["pdftotext", "-layout", str(pdf), "-"], text=True)
    tree = ET.fromstring(subprocess.check_output(["pdftotext", "-bbox", str(pdf), "-"]))
    pages = tree.findall(".//h:page", NS)
    columns = [[], [], [], []]
    headers = 0
    seen_exams = set()
    signing_started = False
    for index, page in enumerate(pages, 1):
        words = [{"text": w.text or "", **{k: float(w.attrib[k]) for k in ("xMin", "xMax", "yMin", "yMax")}} for w in page.findall("h:word", NS)]
        page_text = " ".join(w["text"] for w in words)
        assert f"Página {index} de {len(pages)}" in page_text, (pdf.name, index, "page count")
        assert "Paciente:" in page_text and "Orden:" in page_text, (pdf.name, index, "identity")
        assert re.search(r"Emisión: \d{2}/\d{2}/\d{4}(?: |$)", page_text), (pdf.name, index, "emission date format")
        assert "Afiliación:" not in page_text and "Identidad conservada" not in page_text and "Identidad actual confirmada" not in page_text, (pdf.name, index, "hidden identity metadata printed")
        assert "Vigencia actual:" not in page_text and "Observación autorizada:" not in page_text, (pdf.name, index, "removed report text printed")
        assert not re.search(r"\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2})?", page_text), (pdf.name, index, "unformatted metadata date")
        # These fixtures contain no page reserved exclusively for an empty table.
        assert len(words) > 25, (pdf.name, index, "empty page")
        starts = [w["yMin"] for w in words if w["text"] == "Parámetro" and EDGES[0] < w["xMin"] < EDGES[1]
                  and any(other["text"] == "Resultado" and abs(other["yMin"] - w["yMin"]) < 1 for other in words)]
        assert not (signing_started and starts), (pdf.name, index, "results after signatures")
        signing = [w["yMin"] for w in words if w["text"] == "RESPONSABLES,"]
        if signing:
            signing_started = True
        raster_rgb = Image.open(io.BytesIO(subprocess.check_output([
            "pdftoppm", "-f", str(index), "-l", str(index), "-singlefile", "-r", "72", str(pdf)
        ]))).convert("RGB")
        raster = raster_rgb.convert("L")
        qr = raster.crop((163 * MM, 10 * MM, 195 * MM, 42 * MM))
        assert sum(pixel < 100 for pixel in qr.getdata()) > 1000, (pdf.name, index, "QR missing from header")
        assert all(w["xMax"] < 163 * MM for w in words if w["yMin"] < 42 * MM), (pdf.name, index, "header text overlaps QR")
        for start in starts:
            headers += 1
            header_words = [w for w in words if abs(w["yMin"] - start) < 1]
            assert all(label in [w["text"] for w in header_words] for label in ("Parámetro", "Resultado", "Unidad", "Valores", "de", "referencia")), (pdf.name, index, "four headers")
            for label, column in (("Parámetro", 0), ("Resultado", 1), ("Unidad", 2), ("Valores", 3)):
                heading = next(w for w in header_words if w["text"] == label)
                assert EDGES[column] < heading["xMin"] < heading["xMax"] < EDGES[column + 1]
            assert len(starts) == 1, (pdf.name, index, "table restarted within page")
            footer = next(w["yMin"] for w in words if w["text"] == "Página")
            end = min(signing, default=footer)
            body = [w for w in words if start + 10 < w["yMin"] < end - 1]
            assert not any(w["text"] in ("Profesional:", "Firma", "Responsable", "Identificador") for w in body), (pdf.name, index, "signature inside results table")
            assert body, (pdf.name, index, "orphan table header")

            def border_at(x, y):
                # Require a vertical run to reject text ink crossing a separator.
                return all(min(raster.getpixel((px, py)) for px in range(round(x) - 1, round(x) + 2)) < 210
                           for py in range(int(y) - 6, int(y) + 7))

            # Every row, including clinical evidence, has uninterrupted outer
            # edges. Inner separators distinguish four-cell rows from bands.
            last = max(y for y in range(int(start + 10), int(end)) if border_at(EDGES[0], y) and border_at(EDGES[-1], y))
            for y in range(int(start + 10), last):
                assert border_at(EDGES[0], y) and border_at(EDGES[-1], y), (pdf.name, index, "gap in continuous table", y)
            parameters = []
            for w in sorted(body, key=lambda word: (round(word["yMin"], 2), word["xMin"])):
                assert w["yMax"] < footer - 1, (pdf.name, index, "footer overlap")
                assert EDGES[0] < w["xMin"] < w["xMax"] < EDGES[-1], (pdf.name, index, "table overflow", w)
                middle = (w["yMin"] + w["yMax"]) / 2
                assert border_at(EDGES[0], middle) and border_at(EDGES[-1], middle), (pdf.name, index, "text outside table", w)
                if all(border_at(edge, middle) for edge in EDGES[1:-1]):
                    column = next((i for i in range(4) if EDGES[i] < w["xMin"] < EDGES[i + 1]), None)
                    assert column is not None and w["xMax"] < EDGES[column + 1] - 1, (pdf.name, index, "cell overflow", w)
                    columns[column].append(w["text"])
                    parameters.append(w)
            # Area/exam headings occupy a full-width band with no inner borders.
            for heading in [w for w in body if w["text"] in ("ÁREA:", "Examen:")]:
                middle = (heading["yMin"] + heading["yMax"]) / 2
                assert not any(border_at(edge, middle) for edge in EDGES[1:-1]), (pdf.name, index, "heading not full width")
                accent = {"neutral": (17, 17, 17), "teal": (0, 102, 102), "blue": (22, 58, 112), "burgundy": (112, 32, 56)}[theme]
                box = tuple(heading[key] for key in ("xMin", "yMin", "xMax", "yMax"))
                assert any(max(abs(a - b) for a, b in zip(pixel, accent)) < 25 for pixel in raster_rgb.crop(box).getdata()), (pdf.name, index, "published theme lost")
                if heading["text"] == "ÁREA:":
                    assert 230 <= raster.getpixel((round(EDGES[0] + 3), int(middle))) <= 245, (pdf.name, index, "area background missing")
                assert any(w["yMin"] > heading["yMin"] + 1 for w in body), (pdf.name, index, "orphan clinical heading")
            exams = sorted((w for w in body if w["text"] == "Examen:"), key=lambda w: w["yMin"])
            for exam_index, heading in enumerate(exams):
                same_line = sorted((w for w in body if abs(w["yMin"] - heading["yMin"]) < 1), key=lambda w: w["xMin"])
                code = same_line[1]["text"]
                next_y = exams[exam_index + 1]["yMin"] if exam_index + 1 < len(exams) else footer
                if code in validated_exams and code not in seen_exams:
                    assert any(heading["yMin"] < w["yMin"] < next_y for w in parameters), (pdf.name, index, "exam heading separated from first result", code)
                seen_exams.add(code)
            # Text extraction cannot detect invisible/gray text: check actual raster ink.
            samples = [w for w in header_words if w["text"] in ("Parámetro", "Resultado", "Unidad", "referencia")]
            samples += [next(w for w in body if len(w["text"]) > 4)]
            for word in samples:
                box = tuple(word[key] for key in ("xMin", "yMin", "xMax", "yMax"))
                assert min(raster.crop(box).getdata()) < 100, (pdf.name, index, "table text lost contrast", word)
            # Any intersecting word boxes indicate a second wrap or column collision.
            for i, left in enumerate(body):
                for right in body[i + 1:]:
                    x_overlap = min(left["xMax"], right["xMax"]) - max(left["xMin"], right["xMin"])
                    y_overlap = min(left["yMax"], right["yMax"]) - max(left["yMin"], right["yMin"])
                    assert x_overlap < 0.5 or y_overlap < 0.5, (pdf.name, index, "overlapping text", left, right)
        # Include the final signature section in overlap checks, even on pages
        # that contain no result table.
        for i, left in enumerate(words):
            for right in words[i + 1:]:
                x_overlap = min(left["xMax"], right["xMax"]) - max(left["xMin"], right["xMin"])
                y_overlap = min(left["yMax"], right["yMax"]) - max(left["yMin"], right["yMin"])
                assert x_overlap < 0.5 or y_overlap < 0.5, (pdf.name, index, "page text overlap", left, right)
    assert signing_started, (pdf.name, "missing final signatures section")
    assert headers > 0, (pdf.name, "missing real table")
    return text, pages, [compact(" ".join(column)) for column in columns], headers


pdf = pathlib.Path(sys.argv[1])
text, pages, _, _ = inspect(pdf)
assert 1 < len(pages) < 12
assert "Versión de emisión: 7" in text
assert "PACIENTE SINTETICO DF011" in text and "ORD-DF011-SINTETICA" in text
assert "Firma y sello:" in text
print(f"PASS {pdf.name}: {len(pages)} Letter pages, four column geometry, no overlaps, identity and totals")

for example in ("short", "mixed", "long-text", "long-table", "areas", "same-area", "observations", "partial", "styled", "blue", "burgundy", "responsibles", "no-professional", "legacy-exception"):
    path = pdf.parent / f"table-{example}.pdf"
    document = json.loads(path.with_suffix(".json").read_text())
    text, pages, columns, headers = inspect(path, document["presentation"].get("color_theme", "neutral"), {line["exam_code"] for line in document["lines"] if line["validation"] is not None})
    groups = {json.dumps([line["validation"]["validated_by"], line["validation"].get("professional_snapshot"), line["validation"]["context_snapshot"].get("validator_identity"), line["validation"]["context_snapshot"].get("signature_snapshot")], sort_keys=True) for line in document["lines"] if line["validation"] is not None}
    assert compact(text).count("Firmaysello:") == len(groups), (example, "duplicated or missing signature blocks")
    assert text.count("RESPONSABLES, FIRMAS Y SELLOS") == 1
    if example == "legacy-exception":
        assert "Decisión 2 | Actor 3 | 02/11/2026" in text
    if example == "no-professional":
        assert "Identificador del validador: 1" in text
    for line in document["lines"]:
        if line["validation"] and line["validation"].get("comment"):
            assert compact(line["validation"]["comment"]) not in compact(text), (example, "removed generic observation body printed")
    for line in document["lines"]:
        if line["validation"] is not None:
            validation = line["validation"]
            date = datetime.fromisoformat(validation["validated_at"].replace("Z", "+00:00")).strftime("%d/%m/%Y")
            assert f"Validación {validation['id']} | Revisión aprobada {validation['approved_revision']} | {date}" in text
            date = datetime.fromisoformat(validation["context_snapshot"]["reference_date"]).strftime("%d/%m/%Y")
            assert "Fecha de referencia: " + date in text
    expected_date = datetime.fromisoformat(document["issued_at"].replace("Z", "+00:00")).strftime("%d/%m/%Y")
    assert "Emisión: " + expected_date in text, (example, "emission date changed")
    for line in document["lines"]:
        if line["validation"] is None:
            continue
        for result in line["validation"]["results_snapshot"]:
            assert compact(result["parameter_snapshot"]["name"]) in columns[0], (example, "parameter lost")
            assert compact(result["value"]) in columns[1], (example, result["parameter_code"], "exact value lost")
            if result["option_label"]:
                assert compact(result["option_label"]) in columns[1]
            assert compact(result["unit"] or "—") in columns[2]
        for reference in line["validation"]["reference_snapshot"]:
            rule = reference["reference"]
            if rule:
                for key, prefix in (("min", "Mínimo "), ("max", "Máximo ")):
                    if key in rule:
                        assert compact(prefix + str(rule[key])) in columns[3]
                if "text" in rule:
                    assert compact(rule["text"]) in columns[3]
                if "allowed_options" in rule:
                    assert compact("Opciones de referencia: " + ", ".join(rule["allowed_options"])) in columns[3]
    if example == "short":
        assert len(pages) == 1
    if example in ("mixed", "areas", "same-area", "observations", "partial", "styled", "blue", "burgundy", "responsibles", "no-professional", "legacy-exception"):
        for label in ("ALTO", "BAJO", "CRÍTICO", "FUERA DE REFERENCIA", "SIN CLASIFICACIÓN APLICABLE", "SIN CLASIFICACIÓN HISTÓRICA"):
            assert compact(label) in columns[1], (example, label)
        assert compact("No configurada") in columns[3]
        assert compact("Sin referencia aplicable en la evidencia aprobada") in columns[3]
        assert compact("Referencia textual exclusivamente ficticia") in columns[3]
        assert compact("Opciones de referencia: NO") in columns[3]
    if example == "long-text":
        assert len(pages) > 2 and headers <= len(pages)
        assert "FIN_EXTENSO" in columns[1] and "FIN_REFERENCIA" in columns[3]
    if example == "long-table":
        assert len(pages) > 2 and headers >= len(pages)
        assert "[P065]" in columns[0]
    if example in ("areas", "same-area"):
        assert any(len(set(re.findall(r"EXAM-[1-4]|DEMO", page))) >= 2 for page in text.split("\f")), (example, "no adjacent exams in continuous table")
        assert headers <= len(pages), (example, "header restarted per exam")
    if example == "areas":
        assert text.index("Área sintética B") < text.index("Área sintética A")
        assert text.index("EXAM-4") < text.index("EXAM-3")
    if example == "same-area":
        assert "Área sintética B" not in text
        assert text.index("EXAM-4") < text.index("EXAM-3") < text.index("EXAM-2") < text.index("Examen: DEMO")
    if example == "observations":
        for scope in ("parameter", "exam", "area", "report"):
            assert "OBSERVACION_SINTETICA_" + scope in text
        assert "Autor ficticio" in text and "Autorizada: 02/11/2026" in text
        assert "No realizado" in text and "Motivo ficticio conservado" in text
        assert "NO-REALIZADO" not in columns[0]
    if example == "partial":
        assert "INFORME PARCIAL" in text and "EXÁMENES PENDIENTES" in text
        assert "PENDIENTE" not in columns[0]
    if example == "styled":
        assert "Encabezado institucional ficticio" in text and "Pie institucional de ejemplo" in text
        assert "Image" in subprocess.check_output(["pdfimages", "-list", str(path)], text=True) or "image" in subprocess.check_output(["pdfimages", "-list", str(path)], text=True)
    print(f"PASS {path.name}: {len(pages)} pages, {headers} table headers, exact historical values and separate columns")

# Issuance regression exports: actual service, existing eligibility and snapshots.
for name in ("issued-partial", "issued-complete"):
    path = pdf.parent / f"{name}.pdf"
    text, _, columns, _ = inspect(path)
    assert "12345678901234567890.123456789" in columns[1]
    for value in ("FICTICIO", "YES — Fictitious YES", "A — Fictitious A"):
        assert compact(value) in columns[1], (name, "clinical result type or option label lost", value)
    assert "DEMO-U" in columns[2]
    assert ("INFORME PARCIAL" if name.endswith("partial") else "INFORME COMPLETO") in text
    print(f"PASS {path.name}: service-issued PDF retains exact numeric result in its own column")

# Existing clinical evidence remains inside the same outer table, including
# authorized observations and the private raster signature in issued fixtures.
for name in ("critical-observations", "digital-signature"):
    path = pdf.parent / f"{name}.pdf"
    text, _, _, _ = inspect(path)
    if name == "critical-observations":
        for label in ("CRÍTICO", "Observación ficticia de parámetro", "Observación ficticia de examen", "Observación ficticia de área", "Observación ficticia general", "Autor:"):
            assert compact(label) in compact(text), (name, label)
    else:
        assert compact("Firma digitalizada autorizada de Profesional sintético") in compact(text)
        assert len(subprocess.check_output(["pdfimages", "-list", str(path)], text=True).splitlines()) > 2
    print(f"PASS {path.name}: clinical evidence retained; signatures follow results")
