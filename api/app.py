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
FULL_MODEL = os.getenv("FULL_MODEL", "gpt-5")
FAST_MODEL = os.getenv("FAST_MODEL", "gpt-4o-mini")  # keep FAST truly fast

# ---------- OpenAI clients: FAST (short) and FULL (long) ----------
proxy = os.getenv("HTTPS_PROXY") or os.getenv("HTTP_PROXY")

fast_timeout = httpx.Timeout(10.0, read=20.0, write=10.0, connect=10.0)
full_timeout = httpx.Timeout(30.0, read=180.0, write=30.0, connect=15.0)

# Use HTTP/2 for lower handshake latency + persistent connections
if proxy:
    fast_http = httpx.Client(proxies=proxy, timeout=fast_timeout, http2=True)
    full_http = httpx.Client(proxies=proxy, timeout=full_timeout, http2=True)
else:
    fast_http = httpx.Client(timeout=fast_timeout, http2=True)
    full_http = httpx.Client(timeout=full_timeout, http2=True)

fast_client = OpenAI(api_key=OPENAI_API_KEY, http_client=fast_http)
full_client = OpenAI(api_key=OPENAI_API_KEY, http_client=full_http)

# Database settings
DB_HOST = os.getenv("DB_HOST")
DB_NAME = os.getenv("DB_NAME")
DB_USER = os.getenv("DB_USER")
DB_PASS = os.getenv("DB_PASS")
DB_PORT = int(os.getenv("DB_PORT", 3306))

app = Flask(__name__)
app.secret_key = os.getenv("SECRET_KEY", "default_secret_key")

# CORS
CORS(app, resources={r"/*": {"origins": "*"}}, supports_credentials=False)

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

# ---------- Stream the response using SSE ----------
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

        # Build the prompt
        prompt = build_prompt(note, specialty, "", detected)
        messages = [
            {"role": "system", "content": "You are a medical expert that returns only a well-structured, comprehensive 10-section diagnostic analysis."},
            {"role": "user", "content": prompt},
        ]

        def generate():
            full_text_parts = []
            emitted_any = False

            # 1) Start streaming
            try:
                resp = full_client.chat.completions.create(
                    model=FULL_MODEL,
                    messages=messages,
                    stream=True,  # Enable streaming
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
                # Handle errors in case streaming fails
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
                            temperature=0.2,  # Default temperature is 1 for GPT-5
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

            # 3) Save final result to DB
            analysis = "".join(full_text_parts).strip()
            try:
                conn = get_connection()
                cursor = conn.cursor()
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

# Run the Flask app
if __name__ == '__main__':
    threading.Thread(target=process_pending_jobs, daemon=True).start()
    app.run(host='0.0.0.0', port=5000, debug=False, use_reloader=False, threaded=True)
