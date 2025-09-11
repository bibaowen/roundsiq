from __future__ import annotations
from flask import Flask, request, jsonify, render_template_string
from dotenv import load_dotenv
import os, io, base64, json, traceback, threading, time
import mysql.connector
from mysql.connector import Error
from pathlib import Path
from openai import OpenAI
import httpx
# NEW
from flask_cors import CORS

# ---------- Load environment variables ----------
load_dotenv(dotenv_path=Path(__file__).parent / ".env")
OPENAI_API_KEY = os.getenv("OPENAI_API_KEY")
if not OPENAI_API_KEY:
    raise RuntimeError("OPENAI_API_KEY is missing. Set it in .env or environment variables.")

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

# ---------- OpenAI client ----------
proxy = os.getenv("HTTPS_PROXY") or os.getenv("HTTP_PROXY")
if proxy:
    client = OpenAI(api_key=OPENAI_API_KEY,
                    http_client=httpx.Client(proxies=proxy, timeout=60))
else:
    client = OpenAI(api_key=OPENAI_API_KEY)

# ---------- Database settings ----------
DB_HOST = os.getenv("DB_HOST")
DB_NAME = os.getenv("DB_NAME")
DB_USER = os.getenv("DB_USER")
DB_PASS = os.getenv("DB_PASS")
DB_PORT = int(os.getenv("DB_PORT", 3306))


app = Flask(__name__)
app.secret_key = os.getenv("SECRET_KEY", "default_secret_key")

# NEW — allow your web app to call Flask from another origin/port
CORS(
    app,
    resources={r"/*": {"origins": "*"}},
    supports_credentials=False,
)


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
    """Idempotently add the async columns and composite index used by the worker."""
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
        add_col('clinical_analyses', 'status',              "status VARCHAR(20) NOT NULL DEFAULT 'completed'")
        add_col('clinical_analyses', 'images_json',         "images_json JSON NULL")
        add_col('clinical_analyses', 'detected_conditions', "detected_conditions JSON NULL")
        add_col('clinical_analyses', 'updated_at',          "updated_at TIMESTAMP NULL DEFAULT NULL")
        add_col('clinical_analyses', 'error_message',       "error_message TEXT NULL")

        # Check if an index named idx_status_created exists
        cur.execute("""
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME='clinical_analyses'
              AND INDEX_NAME='idx_status_created'
        """)
        name_exists = (cur.fetchone()[0] > 0)

        # Or an equivalent composite index on (status, created_at) already exists under a different name
        cur.execute("""
            SELECT INDEX_NAME
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME='clinical_analyses'
            ORDER BY SEQ_IN_INDEX
        """)
        rows = cur.fetchall()
        # Build {index_name: [col1, col2, ...]} map
        cur.execute("""
            SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME='clinical_analyses'
            ORDER BY INDEX_NAME, SEQ_IN_INDEX
        """)
        idx_cols = {}
        for idx_name, col_name, seq in cur.fetchall():
            idx_cols.setdefault(idx_name, []).append(col_name)

        equivalent_exists = any(cols == ['status', 'created_at'] for cols in idx_cols.values())

        if not name_exists and not equivalent_exists:
            try:
                cur.execute("ALTER TABLE clinical_analyses ADD INDEX idx_status_created (status, created_at)")
            except mysql.connector.Error as e:
                # Ignore duplicate key error if another process created it moments ago
                if e.errno != 1061:
                    raise

        conn.commit()
    except Exception as e:
        # Keep running even if schema tweak failed; just log once.
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
        cursor.close()
        conn.close()
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



# ---------- Core measure guidance mapping ----------
GUIDANCE_DATA = {
    "sepsis": {
        "triggers": ["sepsis", "septic shock", "severe sepsis", "sep-1"],
        "prompt": "Analyze this case for sepsis management per CMS SEP-1 and Surviving Sepsis Campaign guidelines. Provide a detailed 3- and 6-hour bundle checklist, diagnostic workup, empiric antibiotic options with doses, initial fluid resuscitation details (including volume/kg), vasopressor initiation criteria and agents, lactate monitoring, source control measures, and reassessment plan. Include CMS compliance checklist and references."
    },
    "heart failure": {
        "triggers": ["heart failure", "hf", "chf", "congestive heart failure"],
        "prompt": "Generate a detailed inpatient heart failure management plan per CMS HF core measures and AHA/ACC guidelines. Include diagnostic evaluation, IV diuretic regimen with dosing and monitoring, guideline-directed medical therapy (GDMT) optimization, discharge education requirements, follow-up planning, and documentation points to meet CMS HF-1 (LV function assessment, discharge instructions, ACEi/ARB/ARNI). Provide quality measure checklist and guideline references."
    },
    "ami": {
        "triggers": ["ami", "acute myocardial infarction", "mi", "stemi", "nstemi"],
        "prompt": "Provide a comprehensive AMI management plan per CMS AMI core measures and ACC/AHA guidelines. Include reperfusion strategy timing (PCI vs. fibrinolysis), antiplatelet and anticoagulant dosing, adjunctive medications, monitoring parameters, discharge medication list per CMS AMI-10, smoking cessation counseling requirements, and documentation needed for CMS compliance. Include relevant guideline citations."
    },
    "stroke": {
        "triggers": ["stroke", "tia", "cva", "transient ischemic attack", "ischemic stroke"],
        "prompt": "Generate an acute ischemic stroke management plan per CMS stroke core measures and AHA/ASA guidelines. Include eligibility assessment for thrombolysis or thrombectomy, antiplatelet therapy timing/dosing, dysphagia screening steps, DVT prophylaxis, statin initiation, BP management targets, and patient/family education. Provide CMS STK-1 to STK-10 checklist with documentation requirements and references."
    },
    "vte": {
        "triggers": ["vte", "venous thromboembolism", "dvt", "deep vein thrombosis", "pe", "pulmonary embolism"],
        "prompt": "Develop a detailed plan for VTE prophylaxis or treatment per CMS VTE core measures and CHEST guidelines. Include risk stratification, agent selection with dosing, timing, contraindication documentation, and discharge anticoagulation education requirements. Include CMS VTE-1 and VTE-2 compliance checklist and references."
    },
    "pneumonia": {
        "triggers": ["pneumonia", "cap", "community acquired pneumonia", "hap", "hospital acquired pneumonia", "vap", "ventilator associated pneumonia"],
        "prompt": "Provide an inpatient pneumonia management plan per CMS PN core measures and IDSA/ATS guidelines. Include diagnostic workup, empiric antibiotic regimens with doses (CAP vs. HAP/VAP), timing of first dose, blood culture guidance, oxygenation assessment, vaccine counseling, and discharge planning. Provide CMS compliance checklist and references."
    },
    "scip": {
        "triggers": ["scip", "surgical care improvement", "perioperative infection prevention"],
        "prompt": "Create a perioperative infection prevention checklist per CMS SCIP core measures. Include antibiotic selection/timing/dosing, appropriate discontinuation timing, perioperative glucose control, normothermia maintenance, and hair removal recommendations. Include CMS SCIP compliance points and references."
    },
    "readmission": {
        "triggers": ["readmission", "hrpp", "high risk discharge"],
        "prompt": "Provide a high-risk discharge management plan to prevent readmission per CMS HRRP quality measures. Include patient risk stratification, discharge medication reconciliation, follow-up appointment scheduling, post-discharge call checklist, home health referrals, and education requirements. Include references to CMS readmission prevention standards."
    },
    "dka": {
        "triggers": ["dka", "diabetic ketoacidosis", "hhs", "hyperosmolar hyperglycemic state"],
        "prompt": "Generate a detailed inpatient management plan for DKA or HHS per ADA guidelines and hospital best practices. Include diagnostic criteria, stepwise fluid resuscitation plan (type, volume, and rate), insulin therapy with dosing and transition to subcutaneous insulin, electrolyte monitoring and replacement (potassium, phosphate), identification and treatment of precipitating factors, criteria for resolution, and patient education prior to discharge. Include CMS quality documentation requirements and references."
    }
}

# ---------- Common GPT-5 analysis logic ----------
def build_prompt(note: str, specialty: str, images_meta_text: str, detected_conditions):
    modifier = get_prompt_modifier(specialty)
    extra_guidance = "\n".join(
        f"\n### SPECIAL GUIDANCE: {cond.upper()}\n{GUIDANCE_DATA[cond]['prompt']}"
        for cond in detected_conditions
    )

    prompt_text = f"""You are a highly trained clinical decision support AI.
Analyze the clinical case below and return structured diagnostic reasoning using these 10 sections:

1. Differential Diagnosis
2. Pathophysiology Integration
3. Diagnostic Workup
4. Treatment and Medications
5. Risk Stratification & Clinical Judgment
6. Management of Chronic Conditions
7. Infection Consideration & Antibiotics
8. Disposition & Follow-Up
9. Red Flags or Missed Diagnoses
10. Clinical Guidelines Integration

{modifier}

CASE NOTE:
{note}

{extra_guidance if extra_guidance else ''}

IMAGE METADATA:
{images_meta_text if images_meta_text else 'No images attached.'}
"""
    return prompt_text

def detect_conditions(note: str):
    lower_note = (note or "").lower()
    return [
        cond for cond, data in GUIDANCE_DATA.items()
        if any(trigger in lower_note for trigger in data["triggers"])
    ]

def run_gpt5_analysis(note: str, specialty: str, images_data_uris: list, filenames_meta: list):
    detected_conditions = detect_conditions(note)
    prompt_text = build_prompt(
        note=note,
        specialty=specialty,
        images_meta_text=", ".join(filenames_meta),
        detected_conditions=detected_conditions
    )

    # Build content blocks (vision support if images provided)
    content_blocks = [{"type": "text", "text": prompt_text}]
    for uri in images_data_uris:
        content_blocks.append({"type": "image_url", "image_url": {"url": uri}})

    resp = client.chat.completions.create(
        model="gpt-5",
        #model="gpt-4o",
        messages=[
            {"role": "system", "content": "You are a medical expert that returns only formatted diagnostic analysis."},
            {"role": "user", "content": content_blocks}
        ],
    )
    full_response = resp.choices[0].message.content.strip()
    return full_response, detected_conditions

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

        # Handle form-data or JSON
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

        # Run GPT-5 (blocking)
        analysis, detected = run_gpt5_analysis(note, specialty, images_data_uris, filenames_meta)

        # Save to DB
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

# ---- Async queue endpoint ----
@app.route('/queue_analysis', methods=['POST'])
def queue_analysis():
    """
    Accepts JSON or multipart (note, specialty, optional images[]).
    Stores a pending job so the worker can process it in background.
    """
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
            doctor_id = data.get("doctor_id")  # may be None

        if not note:
            return jsonify({"error": "Missing clinical note"}), 400

        patient_name = note.split(",")[0].strip() if "," in note else "Unknown"

        ensure_columns()
        conn = get_connection()
        cursor = conn.cursor()
        cursor.execute("""
            INSERT INTO clinical_analyses (doctor_id, patient_name, specialty, note, status, images_json, created_at)
            VALUES (%s, %s, %s, %s, 'pending', %s, CURRENT_TIMESTAMP)
        """, (doctor_id, patient_name, specialty, note, json.dumps(images_data_uris or [])))
        analysis_id = cursor.lastrowid
        conn.commit()
        cursor.close(); conn.close()

        return jsonify({"analysis_id": analysis_id, "status": "pending"})

    except Exception as e:
        traceback.print_exc()
        return jsonify({"error": str(e)}), 500

# ---- Async status fetch ----
@app.route('/get_analysis', methods=['GET'])
def get_analysis():
    analysis_id = request.args.get("id")
    if not analysis_id:
        return jsonify({"error": "Missing analysis ID"}), 400
    try:
        ensure_columns()
        conn = get_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute("""
            SELECT id, patient_name, specialty, note, analysis, status, images_json, detected_conditions, created_at, updated_at
            FROM clinical_analyses WHERE id = %s
        """, (analysis_id,))
        record = cursor.fetchone()
        cursor.close(); conn.close()
        if not record:
            return jsonify({"error": "Analysis not found"}), 404

        # decode JSON columns for neatness
        try:
            record["images_json"] = json.loads(record["images_json"]) if record["images_json"] else []
        except Exception:
            record["images_json"] = []
        try:
            record["detected_conditions"] = json.loads(record["detected_conditions"]) if record["detected_conditions"] else []
        except Exception:
            record["detected_conditions"] = []

        return jsonify(record)
    except Exception as e:
        return jsonify({"error": str(e)}), 500

# ---------- Background worker ----------
def process_pending_jobs():
    ensure_columns()
    last_beat = 0
    while True:
        try:
            if time.time() - last_beat > 15:
                print("[worker] heartbeat OK")
                last_beat = time.time()

            # --- claim one job atomically ---
            conn = get_connection()
            conn.start_transaction()  # explicit TX
            cursor = conn.cursor(dictionary=True)

            # Prefer SKIP LOCKED on MySQL 8.0+
            try:
                cursor.execute("""
                    SELECT id, patient_name, specialty, note, images_json
                    FROM clinical_analyses
                    WHERE status = 'pending'
                    ORDER BY created_at ASC
                    LIMIT 1
                    FOR UPDATE SKIP LOCKED
                """)
            except mysql.connector.errors.ProgrammingError:
                # Fallback for MySQL < 8.0 (no SKIP LOCKED)
                cursor.execute("""
                    SELECT id, patient_name, specialty, note, images_json
                    FROM clinical_analyses
                    WHERE status = 'pending'
                    ORDER BY created_at ASC
                    LIMIT 1
                    FOR UPDATE
                """)

            job = cursor.fetchone()
            if job:
                cursor.execute("""
                    UPDATE clinical_analyses
                    SET status = 'processing', updated_at = CURRENT_TIMESTAMP, error_message = NULL
                    WHERE id = %s AND status = 'pending'
                """, (job['id'],))
            conn.commit()
            cursor.close(); conn.close()

            if not job:
                time.sleep(2)
                continue

            print(f"[worker] Processing analysis {job['id']}...")

            # --- do the work ---
            try:
                images_data_uris = []
                try:
                    images_data_uris = json.loads(job.get("images_json") or "[]")
                except Exception:
                    pass
                filenames_meta = [f"image_{i+1}.png (queued)" for i in range(len(images_data_uris))]

                analysis_result, detected = run_gpt5_analysis(
                    note=job['note'],
                    specialty=job['specialty'],
                    images_data_uris=images_data_uris,
                    filenames_meta=filenames_meta
                )

                conn = get_connection()
                cursor = conn.cursor()
                cursor.execute("""
                    UPDATE clinical_analyses
                    SET analysis = %s,
                        status = 'completed',
                        detected_conditions = %s,
                        updated_at = CURRENT_TIMESTAMP,
                        error_message = NULL
                    WHERE id = %s
                """, (analysis_result, json.dumps(detected), job['id']))
                conn.commit()
                cursor.close(); conn.close()
                print(f"[worker] Completed analysis {job['id']}")

            except Exception as proc_err:
                err_text = f"{type(proc_err).__name__}: {proc_err}"
                print(f"[worker] FAILED analysis {job['id']}: {err_text}")
                try:
                    conn = get_connection()
                    cursor = conn.cursor()
                    cursor.execute("""
                        UPDATE clinical_analyses
                        SET status = 'failed',
                            updated_at = CURRENT_TIMESTAMP,
                            error_message = %s
                        WHERE id = %s
                    """, (err_text, job['id']))
                    conn.commit()
                    cursor.close(); conn.close()
                except Exception as mark_err:
                    print("[worker] also failed to mark row as failed:", mark_err)

        except Exception as loop_err:
            print("[worker] loop error:", loop_err)
            time.sleep(5)

@app.route('/health')
def health():
    return jsonify({"ok": True})

# Start worker thread
threading.Thread(target=process_pending_jobs, daemon=True).start()

# ---------- History / Compare ----------
@app.route('/history', methods=['GET'])
def history():
    patient_name = request.args.get("patient_name", "").strip()
    if not patient_name:
        return jsonify({"error": "Missing patient_name"}), 400
    try:
        conn = get_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute("""
            SELECT id, patient_name, specialty, created_at, status
            FROM clinical_analyses
            WHERE patient_name = %s
            ORDER BY created_at DESC
        """, (patient_name,))
        rows = cursor.fetchall()
        cursor.close(); conn.close()
        return jsonify([
            {
                "id": r["id"],
                "patient_name": r["patient_name"],
                "specialty": r["specialty"],
                "status": r.get("status", "completed"),
                "created_at": r["created_at"].isoformat() if r["created_at"] else None
            } for r in rows
        ])
    except Exception as e:
        return jsonify({"error": f"DB error: {str(e)}"}), 500

@app.route('/worker_stats')
def worker_stats():
    try:
        conn = get_connection()
        cur = conn.cursor(dictionary=True)
        cur.execute("SELECT COUNT(*) AS c FROM clinical_analyses WHERE status='pending'")
        pending = cur.fetchone()['c']
        cur.execute("SELECT COUNT(*) AS c FROM clinical_analyses WHERE status='processing'")
        processing = cur.fetchone()['c']
        cur.execute("SELECT COUNT(*) AS c FROM clinical_analyses WHERE status='failed'")
        failed = cur.fetchone()['c']
        cur.close(); conn.close()
        return jsonify({"pending": pending, "processing": processing, "failed": failed})
    except Exception as e:
        return jsonify({"error": str(e)}), 500

@app.route('/compare', methods=['GET'])
def compare():
    id1 = request.args.get("id1")
    id2 = request.args.get("id2")
    render = request.args.get("render", "html")
    if not id1 or not id2:
        return jsonify({"error": "Missing id1 or id2"}), 400
    try:
        conn = get_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute("""SELECT id, patient_name, specialty, note, analysis, created_at
                          FROM clinical_analyses WHERE id = %s""", (id1,))
        record1 = cursor.fetchone()
        cursor.execute("""SELECT id, patient_name, specialty, note, analysis, created_at
                          FROM clinical_analyses WHERE id = %s""", (id2,))
        record2 = cursor.fetchone()
        cursor.close(); conn.close()
        if not record1 or not record2:
            return jsonify({"error": "One or both records not found"}), 404
        if render == "json":
            return jsonify({"comparison": [record1, record2]})
        else:
            return render_template_string(HTML_TEMPLATE, record1=record1, record2=record2)
    except Exception as e:
        return jsonify({"error": f"DB compare error: {str(e)}"}), 500
    
if __name__ == '__main__':
    # Start worker once
    threading.Thread(target=process_pending_jobs, daemon=True).start()
    app.run(host='0.0.0.0', port=5000, debug=False, use_reloader=False, threaded=True)
    

#if __name__ == '__main__':
   # port = int(os.environ.get("PORT", 5000))
    #app.run(host='0.0.0.0', port=port)
