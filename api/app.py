from __future__ import annotations
from flask import Flask, request, jsonify, render_template_string, Response, stream_with_context
from dotenv import load_dotenv
import os, io, base64, json, traceback, threading, time
import mysql.connector
from mysql.connector import Error
from pathlib import Path
from openai import OpenAI
import httpx
from flask_cors import CORS
from openai import BadRequestError  

# ---------- Load environment variables ----------
load_dotenv(dotenv_path=Path(__file__).parent / ".env")
OPENAI_API_KEY = os.getenv("OPENAI_API_KEY")
if not OPENAI_API_KEY:
    raise RuntimeError("OPENAI_API_KEY is missing. Set it in .env or environment variables.")

# ---------- Models ----------
FULL_MODEL = os.getenv("FULL_MODEL", "gpt-40")
FAST_MODEL = os.getenv("FAST_MODEL", "gpt-4o-mini")  # keep FAST truly fast

# ---------- Image handling dependencies ----------
try:
    from PIL import Image as PILImage
    HAVE_PIL = True
except Exception:
    PILImage = None
    HAVE_PIL = False

try:
    import pydicom
    import numpy as np
    HAVE_DICOM = True
except Exception:
    HAVE_DICOM = False

# ---------- OpenAI clients: FAST (short) and FULL (long) ----------
proxy = os.getenv("HTTPS_PROXY") or os.getenv("HTTP_PROXY")

fast_timeout = httpx.Timeout(10.0, read=20.0, write=10.0, connect=10.0)
full_timeout = httpx.Timeout(30.0, read=180.0, write=30.0, connect=15.0)  # longer read for full write-up

# Use HTTP/2 for lower handshake latency + persistent connections
if proxy:
    fast_http = httpx.Client(proxies=proxy, timeout=fast_timeout, http2=True)
    full_http = httpx.Client(proxies=proxy, timeout=full_timeout, http2=True)
else:
    fast_http = httpx.Client(timeout=fast_timeout, http2=True)
    full_http = httpx.Client(timeout=full_timeout, http2=True)

fast_client = OpenAI(api_key=OPENAI_API_KEY, http_client=fast_http)
full_client = OpenAI(api_key=OPENAI_API_KEY, http_client=full_http)

# Back-compat alias in case any path still references `client`
client = full_client

# ---------- Database settings ----------
DB_HOST = os.getenv("DB_HOST")
DB_NAME = os.getenv("DB_NAME")
DB_USER = os.getenv("DB_USER")
DB_PASS = os.getenv("DB_PASS")
DB_PORT = int(os.getenv("DB_PORT", 3306))

app = Flask(__name__)
app.secret_key = os.getenv("SECRET_KEY", "default_secret_key")

# CORS
CORS(app, resources={r"/*": {"origins": "*"}}, supports_credentials=False)

HTML_TEMPLATE = """
<!DOCTYPE html>
<html>
<head>
    <title>Analysis Comparison</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        .container { display: flex; gap: 20px; }
        .panel { flex: 1; border: 1px solid #ccc; padding: 10px; border-radius: 8px; background: #f9f9f9; }
        h2 { font-size: 18px; }
        pre { white-space: pre-wrap; word-wrap: break-word; }
    </style>
</head>
<body>
    <h1>Analysis Comparison</h1>
    <div class="container">
        <div class="panel">
            <h2>Analysis ID: {{ record1.id }}</h2>
            <strong>Patient:</strong> {{ record1.patient_name }}<br>
            <strong>Specialty:</strong> {{ record1.specialty }}<br>
            <strong>Date:</strong> {{ record1.created_at }}<br><br>
            <pre>{{ record1.analysis }}</pre>
        </div>
        <div class="panel">
            <h2>Analysis ID: {{ record2.id }}</h2>
            <strong>Patient:</strong> {{ record2.patient_name }}<br>
            <strong>Specialty:</strong> {{ record2.specialty }}<br>
            <strong>Date:</strong> {{ record2.created_at }}<br><br>
            <pre>{{ record2.analysis }}</pre>
        </div>
    </div>
</body>
</html>
"""

# ---------- DB helpers ----------
def get_connection():
    return mysql.connector.connect(
        host=DB_HOST,
        user=DB_USER,
        password=DB_PASS,
        database=DB_NAME,
        port=DB_PORT,
        connection_timeout=10
    )

def ensure_columns():
    """Idempotently add columns / indexes the worker relies on."""
    try:
        conn = get_connection()
        cur = conn.cursor()

        def add_col(table, col, ddl):
            cur.execute("""
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s
            """, (table, col))
            if cur.fetchone()[0] == 0:
                cur.execute(f"ALTER TABLE {table} ADD COLUMN {ddl}")

        # Columns our code relies on
        add_col('clinical_analyses', 'doctor_id',           "doctor_id INT UNSIGNED NULL")
        add_col('clinical_analyses', 'status',              "status VARCHAR(20) NOT NULL DEFAULT 'completed'")
        add_col('clinical_analyses', 'images_json',         "images_json JSON NULL")
        add_col('clinical_analyses', 'detected_conditions', "detected_conditions JSON NULL")
        add_col('clinical_analyses', 'updated_at',          "updated_at TIMESTAMP NULL DEFAULT NULL")
        add_col('clinical_analyses', 'error_message',       "error_message TEXT NULL")
        add_col('clinical_analyses', 'mode',                "mode VARCHAR(10) NOT NULL DEFAULT 'full'")
        add_col('clinical_analyses', 'upgrade_to_id',       "upgrade_to_id INT UNSIGNED NULL")

        # Composite index on (status, created_at)
        cur.execute("""
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME='clinical_analyses'
              AND INDEX_NAME='idx_status_created'
        """)
        name_exists = (cur.fetchone()[0] > 0)

        if not name_exists:
            try:
                cur.execute("ALTER TABLE clinical_analyses ADD INDEX idx_status_created (status, created_at)")
            except mysql.connector.Error as e:
                if e.errno != 1061:
                    raise

        # Index for upgrade follow-ups
        try:
            cur.execute("CREATE INDEX idx_upgrade_to ON clinical_analyses (upgrade_to_id)")
        except mysql.connector.Error as e:
            if e.errno != 1061:
                raise

        conn.commit()
    except Exception as e:
        print("ensure_columns error (non-fatal):", e)
    finally:
        try:
            cur.close(); conn.close()
        except:
            pass

def get_prompt_modifier(specialty_slug: str) -> str:
    try:
        conn = get_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT prompt_modifier FROM specialties WHERE slug = %s", (specialty_slug,))
        row = cursor.fetchone()
        cursor.close(); conn.close()
        return row["prompt_modifier"] if row and row.get("prompt_modifier") else ""
    except Error as e:
        print("DB error getting specialty modifier:", e)
        traceback.print_exc()
        return ""

# ---------- Image helpers ----------
ALLOWED_EXT = {'png', 'jpg', 'jpeg', 'webp', 'bmp', 'tif', 'tiff', 'dcm', 'dicom'}
MAX_IMAGES = int(os.getenv("MAX_ANALYZE_IMAGES", "8"))

def file_ok(filename: str) -> bool:
    return bool(filename and '.' in filename and filename.rsplit('.', 1)[1].lower() in ALLOWED_EXT)

def pil_to_png_bytes(img) -> bytes:
    buf = io.BytesIO()
    img.convert('RGB').save(buf, format='PNG')
    return buf.getvalue()

def image_file_to_png_bytes(fstorage) -> tuple[bytes, str]:
    fname = fstorage.filename or "upload"
    ext = fname.rsplit('.', 1)[-1].lower() if '.' in fname else ''
    raw = fstorage.read()

    # DICOM → PNG
    if ext in ('dcm', 'dicom'):
        if not HAVE_DICOM:
            raise RuntimeError("DICOM support not available on server")
        ds = pydicom.dcmread(io.BytesIO(raw))
        arr = ds.pixel_array.astype('float32')
        arr = 255*(arr - arr.min()) / max(1e-6, (arr.max() - arr.min()))
        arr = arr.astype('uint8')
        if not HAVE_PIL or PILImage is None:
            raise RuntimeError("Pillow not available to encode PNG")
        im = PILImage.fromarray(arr, mode='L')
        return pil_to_png_bytes(im), "dicom"

    # Standard image → PNG
    if not HAVE_PIL or PILImage is None:
        raise RuntimeError("Pillow not available on server")
    im = PILImage.open(io.BytesIO(raw))
    return pil_to_png_bytes(im), "image"

def b64_data_uri(png_bytes: bytes) -> str:
    return "data:image/png;base64," + base64.b64encode(png_bytes).decode("ascii")

# ---------- Core measure guidance mapping ----------
GUIDANCE_DATA = {
    "sepsis": {"triggers": ["sepsis", "septic shock", "severe sepsis", "sep-1"],
               "prompt": "Analyze this case for sepsis management per CMS SEP-1 and Surviving Sepsis Campaign guidelines. Provide a detailed 3- and 6-hour bundle checklist, diagnostic workup, empiric antibiotic options with doses, initial fluid resuscitation details (including volume/kg), vasopressor initiation criteria and agents, lactate monitoring, source control measures, and reassessment plan. Include CMS compliance checklist and references."},
    "heart failure": {"triggers": ["heart failure", "hf", "chf", "congestive heart failure"],
                      "prompt": "Generate a detailed inpatient heart failure management plan per CMS HF core measures and AHA/ACC guidelines. Include diagnostic evaluation, IV diuretic regimen with dosing and monitoring, guideline-directed medical therapy (GDMT) optimization, discharge education requirements, follow-up planning, and documentation points to meet CMS HF-1 (LV function assessment, discharge instructions, ACEi/ARB/ARNI). Provide quality measure checklist and guideline references."},
    "ami": {"triggers": ["ami", "acute myocardial infarction", "mi", "stemi", "nstemi"],
            "prompt": "Provide a comprehensive AMI management plan per CMS AMI core measures and ACC/AHA guidelines. Include reperfusion strategy timing (PCI vs. fibrinolysis), antiplatelet and anticoagulant dosing, adjunctive medications, monitoring parameters, discharge medication list per CMS AMI-10, smoking cessation counseling requirements, and documentation needed for CMS compliance. Include relevant guideline citations."},
    "stroke": {"triggers": ["stroke", "tia", "cva", "transient ischemic attack", "ischemic stroke"],
               "prompt": "Generate an acute ischemic stroke management plan per CMS stroke core measures and AHA/ASA guidelines. Include eligibility assessment for thrombolysis or thrombectomy, antiplatelet therapy timing/dosing, dysphagia screening steps, DVT prophylaxis, statin initiation, BP management targets, and patient/family education. Provide CMS STK-1 to STK-10 checklist with documentation requirements and references."},
    "vte": {"triggers": ["vte", "venous thromboembolism", "dvt", "deep vein thrombosis", "pe", "pulmonary embolism"],
            "prompt": "Develop a detailed plan for VTE prophylaxis or treatment per CMS VTE core measures and CHEST guidelines. Include risk stratification, agent selection with dosing, timing, contraindication documentation, and discharge anticoagulation education requirements. Include CMS VTE-1 and VTE-2 compliance checklist and references."},
    "pneumonia": {"triggers": ["pneumonia", "cap", "community acquired pneumonia", "hap", "hospital acquired pneumonia", "vap", "ventilator associated pneumonia"],
                  "prompt": "Provide an inpatient pneumonia management plan per CMS PN core measures and IDSA/ATS guidelines. Include diagnostic workup, empiric antibiotic regimens with doses (CAP vs. HAP/VAP), timing of first dose, blood culture guidance, oxygenation assessment, vaccine counseling, and discharge planning. Provide CMS compliance checklist and references."},
    "scip": {"triggers": ["scip", "surgical care improvement", "perioperative infection prevention"],
             "prompt": "Create a perioperative infection prevention checklist per CMS SCIP core measures. Include antibiotic selection/timing/dosing, appropriate discontinuation timing, perioperative glucose control, normothermia maintenance, and hair removal recommendations. Include CMS SCIP compliance points and references."},
    "readmission": {"triggers": ["readmission", "hrpp", "high risk discharge"],
                    "prompt": "Provide a high-risk discharge management plan to prevent readmission per CMS HRRP quality measures. Include patient risk stratification, discharge medication reconciliation, follow-up appointment scheduling, post-discharge call checklist, home health referrals, and education requirements. Include references to CMS readmission prevention standards."},
    "dka": {"triggers": ["dka", "diabetic ketoacidosis", "hhs", "hyperosmolar hyperglycemic state"],
            "prompt": "Generate a detailed inpatient management plan for DKA or HHS per ADA guidelines and hospital best practices. Include diagnostic criteria, stepwise fluid resuscitation plan (type, volume, and rate), insulin therapy with dosing and transition to subcutaneous insulin, electrolyte monitoring and replacement (potassium, phosphate), identification and treatment of precipitating factors, criteria for resolution, and patient education prior to discharge. Include CMS quality documentation requirements and references."}
}

# ---------- Condition detector ----------
def detect_conditions(note: str):
    text = (note or "").lower()
    found = []
    for cond, data in (GUIDANCE_DATA or {}).items():
        triggers = data.get("triggers", [])
        if any(t in text for t in triggers):
            found.append(cond)
    return found

EXPECTED_H2 = [
    "## 1. Differential Diagnosis",
    "## 2. Pathophysiology Integration",
    "## 3. Diagnostic Workup",
    "## 4. Treatment and Medications",
    "## 5. Risk Stratification & Clinical Judgment",
    "## 6. Management of Chronic Conditions",
    "## 7. Infection Consideration & Antibiotics",
    "## 8. Disposition & Follow-Up",
    "## 9. Red Flags or Missed Diagnoses",
    "## 10. Clinical Guidelines Integration",
]

def _has_enough_sections(txt: str) -> bool:
    found = sum(1 for h in EXPECTED_H2 if h in txt)
    return found >= 8

# ---------- Prompt builder ----------
def build_prompt(note: str, specialty: str, images_meta_text: str, detected_conditions):
    modifier = get_prompt_modifier(specialty) or ""
    extra_guidance = ""
    if detected_conditions:
        extra_guidance = "\n".join(
            f"### SPECIAL GUIDANCE: {cond.upper()}\n{GUIDANCE_DATA[cond]['prompt']}"
            for cond in detected_conditions if cond in GUIDANCE_DATA
        )

    headings_block = "\n".join(EXPECTED_H2)

    return f"""
You are a clinical decision support AI. Return ONLY a Markdown report with the
following H2 section headings EXACTLY as written below (no preface text, no appendices):

{headings_block}

Rules:
- Use concise, guideline-aware language. Cite guideline names (no URLs).
- Integrate imaging findings if images are present.
- If a section is not applicable, include it with "N/A" and a brief reason.
- Keep medication doses realistic and include monitoring considerations.

Specialty modifier (may be empty):
{modifier}

{extra_guidance if extra_guidance else ''}

CASE NOTE:
{note}

IMAGE METADATA:
{images_meta_text if images_meta_text else 'No images attached.'}
""".strip()

# ---------- FAST triage (JSON) ----------
def _safe_repair_10_sections(draft: str) -> str:
    if not draft or not draft.strip():
        return ""
    repair_prompt = f"""
Rewrite the following draft into EXACTLY these 10 H2 sections (no extra sections,
no intro/outro text). Use the headings verbatim:

{chr(10).join(EXPECTED_H2)}

Draft to rewrite:
{draft}
""".strip()

    resp2 = full_client.chat.completions.create(
        model=FULL_MODEL,
        messages=[ 
            {
                "role": "system",
                "content": "Rewrite strictly into the exact 10 requested H2 sections. Do not add other sections."
            },
            {"role": "user", "content": repair_prompt},
        ],
        extra_body={"max_completion_tokens": 2200},
    )
    return (resp2.choices[0].message.content or "").strip()

def run_gpt5_analysis(note: str, specialty: str, images_data_uris: list, filenames_meta: list):
    detected_conditions = detect_conditions(note)
    prompt_text = build_prompt(
        note=note,
        specialty=specialty,
        images_meta_text=", ".join(filenames_meta or []),
        detected_conditions=detected_conditions,
    )

    resp = full_client.chat.completions.create(
        model=FULL_MODEL,
        messages=[
            {
                "role": "system",
                "content": "You are a medical expert that returns only a well-structured, comprehensive 10-section diagnostic analysis."
            },
            {"role": "user", "content": prompt_text},
        ],
        extra_body={"max_completion_tokens": 2200},
    )
    draft = (resp.choices[0].message.content or "").strip()

    if not draft:
        retry_prompt = (
            prompt_text
            + "\n\nWrite the full report now using the exact 10 H2 headings above. "
              "Do not add any other sections, prefaces, or appendices."
        )
        resp_retry = full_client.chat.completions.create(
            model=FULL_MODEL,
            messages=[
                {
                    "role": "system",
                    "content": "You are a medical expert that returns only a well-structured, comprehensive 10-section diagnostic analysis."
                },
                {"role": "user", "content": retry_prompt},
            ],
            extra_body={"max_completion_tokens": 2200},
        )
        draft = (resp_retry.choices[0].message.content or "").strip()

    if not draft:
        scaffold = "\n\n".join(f"{h}\nN/A — content unavailable after two attempts."
                               for h in EXPECTED_H2)
        return scaffold, detected_conditions

    if not _has_enough_sections(draft):
        repaired = _safe_repair_10_sections(draft)
        if repaired and _has_enough_sections(repaired):
            draft = repaired

    return draft, detected_conditions

# ---------- Routes ---------- 
@app.route('/')
def home():
    return "✅ RoundsIQ with history & comparison dashboard (MySQL) is running."

# ---- Synchronous (legacy) ----
@app.route('/analyze', methods=['POST'])
def analyze():
    try:
        images_data_uris, filenames_meta = [], []
        note = ''
        specialty = 'general'

        if request.files:
            note = (request.form.get("note") or "").strip()
            specialty = request.form.get("specialty", "general")
            for idx, f in enumerate(request.files.getlist("images")):
                if idx >= MAX_IMAGES or not file_ok(f.filename):
                    continue
                try:
                    png_bytes, kind = image_file_to_png_bytes(f)
                    images_data_uris.append(b64_data_uri(png_bytes))
                    filenames_meta.append(f"{f.filename} ({kind})")
                except Exception as ex:
                    filenames_meta.append(f"{f.filename} (error: {ex})")
        else:
            data = request.get_json(silent=True) or {}
            note = (data.get("note") or "").strip()
            specialty = data.get("specialty", "general")

        if not note:
            return jsonify({"error": "Missing clinical note"}), 400

        patient_name = note.split(",")[0].strip() if "," in note else "Unknown"

        analysis, detected = run_gpt5_analysis(note, specialty, images_data_uris, filenames_meta)

        try:
            conn = get_connection()
            cursor = conn.cursor()
            cursor.execute("""
                INSERT INTO clinical_analyses (patient_name, specialty, note, analysis, status, images_json, detected_conditions, created_at)
                VALUES (%s, %s, %s, %s, 'completed', %s, %s, CURRENT_TIMESTAMP)
            """, (patient_name, specialty, note, analysis, json.dumps(images_data_uris or []), json.dumps(detected)))
            conn.commit()
        finally:
            try:
                cursor.close(); conn.close()
            except:
                pass

        return jsonify({
            "full_response": analysis,
            "summary": f"Processed {len(images_data_uris)} image(s).",
            "detected_conditions": detected
        })

    except Exception as e:
        traceback.print_exc()
        return jsonify({"error": str(e)}), 500

# ---- True streaming (SSE) ----
@app.route('/analyze_stream', methods=['POST', 'GET', 'OPTIONS'])
def analyze_stream():
    try:
        if request.method == 'OPTIONS':
            return ('', 204)

        if request.method == 'GET':
            note = (request.args.get("note") or "").strip()
            specialty = request.args.get("specialty", "general")
        else:
            data = request.get_json(silent=True) or {}
            note = (data.get("note") or "").strip()
            specialty = data.get("specialty", "general")

        if not note:
            return jsonify({"error": "Missing clinical note"}), 400

        patient_name = note.split(",")[0].strip() if "," in note else "Unknown"
        detected = detect_conditions(note)
        prompt = build_prompt(note, specialty, "", detected)
        messages = [
            {"role": "system", "content": "You are a medical expert that returns only a well-structured, comprehensive 10-section diagnostic analysis."},
            {"role": "user", "content": prompt},
        ]

        def generate():
            full_text_parts = []
            emitted_any = False

            # 1) Try true streaming
            try:
                resp = full_client.chat.completions.create(
                    model=FULL_MODEL,
                    messages=messages,
                    stream=True,
                    extra_body={"max_completion_tokens": 2000},
                )
                for chunk in resp:
                    try:
                        choice = chunk.choices[0]
                    except Exception:
                        continue

                    delta = getattr(choice, "delta", None)
                    token = ""
                    if delta is not None:
                        token = getattr(delta, "content", "") or ""
                    else:
                        token = getattr(choice, "text", "") or ""

                    if token:
                        full_text_parts.append(token)
                        emitted_any = True
                        yield f"event: token\ndata:{json.dumps(token)}\n\n"

            except BadRequestError as e:
                # Org not verified for streaming, etc.
                yield f"event: warn\ndata:{json.dumps('Streaming not available; falling back to full response.')}\n\n"
            except Exception as e:
                yield f"event: warn\ndata:{json.dumps('Streaming error: ' + str(e))}\n\n"

                       # 2) Fallback if no tokens were emitted
            if not emitted_any:
                fallback_models = [FULL_MODEL, "gpt-4o", "gpt-4o-mini"]
                analysis = ""
                last_reason = None

                for m in fallback_models:
                    try:
                        resp_full = full_client.chat.completions.create(
                            model=m,
                            messages=messages,
                            # keep it boring to avoid filter surprises
                            temperature=0.2,
                            extra_body={"max_completion_tokens": 3200},
                        )
                        choice = resp_full.choices[0]
                        last_reason = getattr(choice, "finish_reason", None)
                        text = (choice.message.content or "").strip()

                        # Surface what happened
                        yield f"event: debug\ndata:{json.dumps({'model': m, 'finish_reason': last_reason})}\n\n"

                        if text:
                            analysis = text
                            break

                        # If content filter tripped or we got nothing, try next model
                        if last_reason in ("content_filter", None) or text == "":
                            continue

                    except Exception as e2:
                        yield f"event: warn\ndata:{json.dumps(f'Fallback call failed on {m}: {str(e2)[:160]}')}\n\n"
                        continue

                if analysis:
                    yield f"event: token\ndata:{json.dumps(analysis)}\n\n"
                else:
                    msg = "All fallbacks returned empty text"
                    if last_reason:
                        msg += f" (finish_reason={last_reason})"
                    yield f"event: error\ndata:{json.dumps(msg)}\n\n"


            # 3) Persist and finish
            analysis = "".join(full_text_parts).strip()
            try:
                conn = get_connection(); cursor = conn.cursor()
                cursor.execute("""
                    INSERT INTO clinical_analyses
                    (patient_name, specialty, note, analysis, status, created_at)
                    VALUES (%s,%s,%s,%s,'completed',CURRENT_TIMESTAMP)
                """, (patient_name, specialty, note, analysis))
                conn.commit()
            except Exception as db_err:
                yield f"event: warn\ndata:{json.dumps(f'DB save warning: {db_err}')}\n\n"
            finally:
                try: cursor.close(); conn.close()
                except Exception: pass

            yield f"event: done\ndata:{json.dumps({'status':'done','detected_conditions': detected})}\n\n"

        headers = {
            "Content-Type": "text/event-stream",
            "Cache-Control": "no-cache",
            "X-Accel-Buffering": "no",
            "Connection": "keep-alive",
            "Access-Control-Allow-Origin": "*",
            "Access-Control-Allow-Methods": "GET,POST,OPTIONS",
            "Access-Control-Allow-Headers": "Content-Type",
        }
        return Response(stream_with_context(generate()), headers=headers)

    except Exception as e:
        traceback.print_exc()
        return jsonify({"error": str(e)}), 500



if __name__ == '__main__':
    threading.Thread(target=process_pending_jobs, daemon=True).start()
    app.run(host='0.0.0.0', port=5000, debug=False, use_reloader=False, threaded=True)
