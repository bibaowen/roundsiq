<?php
session_start();
ini_set('max_execution_time', 300); // still useful for large uploads
require_once "db.php";

if (!isset($_SESSION['doctor_id'])) {
    header("Location: login.php");
    exit;
}

// ---------- CONFIG ----------
$FLASK_BASE = "http://localhost:5000"; // change if Flask runs elsewhere

// ---------- HELPERS ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function has_column(PDO $pdo, string $table, string $col): bool {
    $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $q->execute([$table, $col]);
    return (bool)$q->fetchColumn();
}
function ensure_images_table(PDO $pdo){
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS clinical_images (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          clinical_input_id INT UNSIGNED NOT NULL,
          path VARCHAR(512) NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_ci (clinical_input_id)
        )
    ");
}

// ---------- AJAX: SAVE AI RESULT ----------
if (($_GET['action'] ?? '') === 'save_ai') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    $clinical_input_id = (int)($input['clinical_input_id'] ?? 0);
    $full_response     = trim((string)($input['full_response'] ?? ''));
    $summary           = trim((string)($input['summary'] ?? ''));

    if ($clinical_input_id <= 0 || $full_response === '') {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'error'=>'Missing clinical_input_id or full_response']);
        exit;
    }
    if ($summary === '') $summary = mb_substr($full_response, 0, 600);

    try {
        $stmt = $pdo->prepare("INSERT INTO ai_responses (clinical_input_id, summary, full_response, created_at)
                               VALUES (:cid, :sum, :full, NOW())");
        $stmt->execute([
            ':cid'  => $clinical_input_id,
            ':sum'  => $summary,
            ':full' => $full_response
        ]);
        echo json_encode(['ok'=>true]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'DB insert failed']);
    }
    exit;
}

// ---------- MAIN: HANDLE FORM POST, SAVE CASE, THEN RENDER SPINNER PAGE ----------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("Method not allowed.");
}

// (Optional) CSRF check if your form sends tokens
if (isset($_POST['csrf_token']) && (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']))) {
    http_response_code(400);
    exit("<h3 class='text-danger'>Security check failed.</h3>");
}

$note       = $_POST['note'] ?? '';
$specialty  = $_POST['specialty'] ?? 'general';

if (empty(trim($note))) {
    http_response_code(400);
    exit("<h3 class='text-danger'>No clinical note provided.</h3>");
}

$doctor_id   = (int)($_SESSION['doctor_id'] ?? 0);
$doctor_name = (string)($_SESSION['doctor_name'] ?? '');

// ---------- Handle uploads (save locally now) ----------
$uploadDir = __DIR__ . '/uploads/xrays';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);

$uploaded_paths = [];
$MAX_FILES = 20;
$MAX_SIZE  = 20 * 1024 * 1024; // 20MB
$allowed_ext = ['png','jpg','jpeg','webp','bmp','tif','tiff','dcm','dicom'];
$allowed_mime_prefix = ['image/','application/dicom'];

if (!empty($_FILES['xrays']) && is_array($_FILES['xrays']['name'])) {
    $count = min(count($_FILES['xrays']['name']), $MAX_FILES);
    for ($i=0; $i<$count; $i++) {
        if ($_FILES['xrays']['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($_FILES['xrays']['size'][$i] > $MAX_SIZE) continue;

        $tmp  = $_FILES['xrays']['tmp_name'][$i];
        $name = $_FILES['xrays']['name'][$i] ?? 'file';
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext, true)) continue;

        $mime = mime_content_type($tmp) ?: '';
        $ok = false;
        foreach ($allowed_mime_prefix as $p) { if (str_starts_with($mime, $p)) { $ok = true; break; } }
        if (!$ok && !in_array($ext, ['dcm','dicom'], true)) continue;

        $safeBase = preg_replace('/[^a-zA-Z0-9._-]/','_', pathinfo($name, PATHINFO_FILENAME));
        $newName  = $safeBase . '_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;
        $dest     = $uploadDir . '/' . $newName;

        if (move_uploaded_file($tmp, $dest)) {
            $uploaded_paths[] = 'uploads/xrays/' . $newName; // relative
        }
    }
}

// ---------- Parse quick patient info from note (optional) ----------
$name='Unknown'; $age=0; $gender='Unknown';
if (preg_match("/^([A-Za-z\s]+),/", $note, $m))   { $name = trim($m[1]); }
if (preg_match("/(\d+)\s*years\s*old/", $note, $m)){ $age = (int)$m[1]; }
if (preg_match("/,\s*(Male|Female|Other|M|F)\s*,/", $note, $m)) { $gender = $m[1]; }

// ---------- Save patient + clinical_input + images NOW (fast) ----------
$patient_id = null;
$clinical_input_id = null;

try {
    if ($name !== 'Unknown' && $age > 0 && $gender !== 'Unknown') {
        $stmt = $pdo->prepare("INSERT INTO patients (name, age, gender, created_at) VALUES (:n,:a,:g,NOW())");
        $stmt->execute([':n'=>$name, ':a'=>$age, ':g'=>$gender]);
        $patient_id = (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("
        INSERT INTO clinical_inputs (patient_id, symptoms, history, lab_results, created_at, note)
        VALUES (:pid, '', '', '', NOW(), :note)
    ");
    $stmt->execute([':pid'=>$patient_id ?: null, ':note'=>$note]);
    $clinical_input_id = (int)$pdo->lastInsertId();

    if (!empty($uploaded_paths)) {
        if (has_column($pdo, 'clinical_inputs', 'images_json')) {
            $pdo->prepare("UPDATE clinical_inputs SET images_json = :j WHERE id = :id")
                ->execute([':j'=>json_encode($uploaded_paths), ':id'=>$clinical_input_id]);
        } else {
            ensure_images_table($pdo);
            $ins = $pdo->prepare("INSERT INTO clinical_images (clinical_input_id, path) VALUES (:cid, :p)");
            foreach ($uploaded_paths as $p) $ins->execute([':cid'=>$clinical_input_id, ':p'=>$p]);
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit("<h3 class='text-danger'>Database error while saving case.</h3><pre>".h($e->getMessage())."</pre>");
}

// ---------- Build payload_note (like your old combined text) ----------
$payload_note = trim(
  "Symptoms: " . ($_POST['symptoms'] ?? '') . "\n" .
  "History: " . ($_POST['history'] ?? '') . "\n" .
  "Lab Results: " . ($_POST['lab_results'] ?? '') . "\n" .
  "Note: " . $note
);

// ---------- Render spinner page that queues + polls asynchronously ----------
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>RoundsIQ • Re‑examination (Processing)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{ --brand-teal:#004d4d; --brand-orange:#d35400; --brand-teal-100:#e6f0f0; }
    body{ background:#f6f8fb; }
    .navbar{ background:linear-gradient(90deg,var(--brand-teal),#003838); }
    .navbar-brand,.navbar-text{ color:#fff !important; }
    .logo{ height:36px; width:auto; border-radius:.5rem; background:#fff; padding:.1rem .25rem; margin-right:.5rem; }
    .spinner { width:20px; height:20px; border:3px solid #dee2e6; border-top-color:#0d6efd; border-radius:50%; animation:spin .7s linear infinite; }
    @keyframes spin { to { transform:rotate(360deg);} }
    pre{ white-space:pre-wrap; word-wrap:break-word; }
    .toast-container { position: fixed; top: 12px; right: 12px; z-index: 1080; }
  </style>
</head>
<body>
<nav class="navbar">
  <div class="container d-flex align-items-center justify-content-between">
    <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
      <img src="images/logo.jpg" class="logo" alt="RoundsIQ"><span>RoundsIQ</span>
    </a>
    <div class="navbar-text">Hello, Dr. <?= h($doctor_name) ?></div>
  </div>
</nav>

<div class="container my-4">
  <div class="card p-4">
    <div class="d-flex align-items-center gap-3">
      <div class="spinner" id="spinner"></div>
      <div>
        <div id="status" class="fw-semibold">Queuing analysis…</div>
        <div id="substatus" class="text-muted small">This page will update automatically.</div>
      </div>
      <div class="ms-auto">
        <a class="btn btn-outline-secondary btn-sm" href="view_case.php?id=<?= (int)$clinical_input_id ?>">Back to Case</a>
        <button id="btnCancel" class="btn btn-outline-secondary btn-sm">Cancel</button>
      </div>
    </div>
  </div>

  <div id="resultCard" class="card p-4 mt-3" style="display:none">
    <h5>RoundsIQ Diagnostic Reasoning</h5>
    <pre id="resultText"></pre>
    <h6 class="mt-3">Detected Conditions</h6>
    <pre id="detected">[]</pre>
    <div class="mt-2 d-flex gap-2">
      <a href="view_case.php?id=<?= (int)$clinical_input_id ?>" class="btn btn-primary">Open Case</a>
      <a href="dashboard.php" class="btn btn-outline-secondary">Dashboard</a>
    </div>
  </div>
</div>

<!-- Bootstrap JS for toast -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Toast -->
<div class="toast-container">
  <div id="liveToast" class="toast text-bg-dark border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body" id="toastMsg">Hello!</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script>
const FLASK_BASE = <?= json_encode($FLASK_BASE) ?>;
const CLINICAL_INPUT_ID = <?= (int)$clinical_input_id ?>;
const PAYLOAD_NOTE = <?= json_encode($payload_note) ?>;
const SPECIALTY = <?= json_encode($specialty) ?>;

const statusEl = document.getElementById('status');
const subEl    = document.getElementById('substatus');
const resultEl = document.getElementById('resultText');
const detEl    = document.getElementById('detected');
const cardEl   = document.getElementById('resultCard');
const btnCancel= document.getElementById('btnCancel');

const toastEl  = document.getElementById('liveToast');
const toastObj = new bootstrap.Toast(toastEl, { delay: 2500 });
function toast(msg){ document.getElementById('toastMsg').textContent = msg; toastObj.show(); }

let cancelled = false;
let pollTimer = null;

// Queue job quickly
(async function main(){
  try {
    const queueRes = await fetch(`${FLASK_BASE}/queue_analysis`, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ note: PAYLOAD_NOTE, specialty: SPECIALTY })
    });
    if (!queueRes.ok) throw new Error('Queue failed: ' + queueRes.status);
    const queued = await queueRes.json();
    if (queued.error) throw new Error(queued.error);

    const id = queued.analysis_id;
    statusEl.textContent = `Queued (ID ${id}). Polling for completion…`;
    subEl.textContent    = 'Usually 10–60 seconds depending on case size.';

    async function poll(){
      if (cancelled) return;
      try {
        const r = await fetch(`${FLASK_BASE}/get_analysis?id=${encodeURIComponent(id)}`);
        if (!r.ok) throw new Error('Status fetch failed');
        const data = await r.json();
        statusEl.textContent = `Status: ${data.status} (ID ${data.id})`;
        if (data.status === 'completed') {
          toast('Analysis ready. Saving…');
          // Save to our DB (ai_responses) via a quick AJAX post to this file
          const payload = {
            clinical_input_id: CLINICAL_INPUT_ID,
            full_response: data.analysis || '',
            summary: (data.analysis || '').slice(0, 600)
          };
          const save = await fetch(`submit.php?action=save_ai`, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload)
          });
          const sjson = await save.json();
          if (!save.ok || !sjson.ok) toast('Warning: could not save to database.');
          resultEl.textContent = data.analysis || '(No analysis text found)';
          detEl.textContent = data.detected_conditions ? JSON.stringify(data.detected_conditions, null, 2) : '[]';
          cardEl.style.display = 'block';
          statusEl.textContent = 'Done.';
          subEl.textContent    = 'You can open the case now.';
          return;
        }
      } catch (e) {
        console.error(e);
        toast('Temporary polling error. Retrying…');
      }
      pollTimer = setTimeout(poll, 3000); // 3s interval
    }
    poll();
  } catch (err) {
    console.error(err);
    statusEl.textContent = 'Error: ' + (err.message || err);
    subEl.textContent    = 'Please go back to the case and try again.';
    toast('Could not queue analysis.');
  }
})();

btnCancel.addEventListener('click', () => {
  cancelled = true;
  if (pollTimer) clearTimeout(pollTimer);
  toast('Polling cancelled.');
  statusEl.textContent = 'Cancelled.';
  subEl.textContent    = 'No further updates.';
});
</script>
</body>
</html>
