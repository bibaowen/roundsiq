<?php
include("auth.php");
include("db.php");

$doctor_name = $_SESSION['doctor_name'] ?? 'Doctor';
$specialty   = $_SESSION['specialty'] ?? 'general';

// CSRF (optional but recommended)
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>RoundsIQ • Submit Clinical Note</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{
      --brand-teal:#004d4d;
      --brand-orange:#d35400;
      --brand-teal-100:#e6f0f0;
      --brand-orange-100:#ffe8d9;
    }
    body { background:#f6f8fb; }
    .navbar{ background:linear-gradient(90deg,var(--brand-teal),#003838); }
    .navbar-brand,.navbar-text{ color:#fff !important; }
    .logo{ height:36px; width:auto; border-radius:.5rem; background:#fff; padding:.1rem .25rem; margin-right:.5rem; }
    .btn-brand{ background:var(--brand-orange); border-color:var(--brand-orange); }
    .btn-brand:hover{ background:#b84300; border-color:#b84300; }

    .card-shell{ border-radius:1rem; border:1px solid #eef2f7; background:#fff; }
    .dropzone {
      border:2px dashed var(--brand-teal);
      background:#ffffff;
      border-radius:1rem;
      padding:1.25rem;
      text-align:center;
      transition:.15s ease-in-out;
    }
    .dropzone.dragover {
      background: var(--brand-teal-100);
      border-color:#007373;
    }
    .thumbs { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:12px; }
    .thumb {
      border:1px solid #e9ecef; border-radius:.75rem; overflow:hidden; position:relative;
      background:#fff;
    }
    .thumb img{ width:100%; height:100px; object-fit:cover; display:block; }
    .thumb .remove {
      position:absolute; top:6px; right:6px;
      border:none; border-radius:999px; padding:.15rem .45rem; line-height:1;
    }
    .help { font-size:.875rem; color:#6c757d; }
  </style>
</head>
<body>
  <nav class="navbar navbar-expand-lg">
    <div class="container">
      <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
        <img src="images/logo.jpg" class="logo" alt="RoundsIQ"><span>RoundsIQ</span>
      </a>
      <div class="ms-auto navbar-text">
        Hello, Dr. <?= htmlspecialchars($doctor_name) ?>
        <a class="btn btn-sm btn-light ms-3" href="dashboard.php">Back to Dashboard</a>
      </div>
    </div>
  </nav>

  <div class="container my-4">
    <div class="card card-shell p-4 p-md-5">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
          <h4 class="mb-0">Submit Clinical Note</h4>
          <small class="text-secondary">Specialty: <strong><?= htmlspecialchars($specialty) ?></strong></small>
        </div>
        <a class="btn btn-outline-secondary" href="dashboard.php">← Back</a>
      </div>

      <form id="noteForm" class="mt-3" action="submit.php" method="POST" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="specialty" value="<?= htmlspecialchars($specialty) ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

        <!-- Clinical note -->
        <div class="mb-3">
          <label for="note" class="form-label">Clinical Note</label>
          <textarea class="form-control" id="note" name="note" rows="6" placeholder="You may type or use dictation below..." required></textarea>
          <div class="invalid-feedback">Please enter a note or use dictation.</div>
        </div>

        <!-- Dictation -->
        <div class="d-flex align-items-center gap-2 mb-2">
          <button id="mic-btn" type="button" class="btn btn-brand">🎤 Start Dictation</button>
          <button id="stop-mic-btn" type="button" class="btn btn-outline-danger" disabled>🛑 Stop</button>
          <small id="mic-indicator" class="text-success" style="display:none;">🎙️ Listening...</small>
        </div>
        <p id="status" class="mt-1 help"></p>

        <hr class="my-4">

        <!-- X-ray upload -->
        <div class="mb-3">
          <label class="form-label">X‑ray Images (optional)</label>
          <div id="dropzone" class="dropzone">
            <p class="mb-2">Drag & drop X‑ray images here, or click to browse.</p>
            <input id="xrayInput" type="file" name="xrays[]" accept=".png,.jpg,.jpeg,.webp,.bmp,.tif,.tiff,.dcm,.dicom" multiple hidden>
            <button type="button" id="browseBtn" class="btn btn-outline-secondary btn-sm">Browse Files</button>
            <div class="help mt-2">Allowed: PNG, JPG, WEBP, BMP, TIFF, DICOM. Up to 20 files, ≤ 20MB each.</div>
          </div>
        </div>

        <!-- Previews -->
        <div id="thumbs" class="thumbs mb-4"></div>

        <div class="d-flex justify-content-end">
          <button type="submit" class="btn btn-brand">Ask Dr. RIQ</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // ---- Dictation ----
    const micBtn = document.getElementById("mic-btn");
    const stopMicBtn = document.getElementById("stop-mic-btn");
    const micIndicator = document.getElementById("mic-indicator");
    const textarea = document.getElementById('note');
    const statusEl = document.getElementById('status');

    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    let recognition = null;
    let isListening = false;
    if (SpeechRecognition) {
      recognition = new SpeechRecognition();
      recognition.continuous = true;
      recognition.interimResults = true;
      recognition.lang = 'en-US';

      micBtn.addEventListener("click", () => {
        if (!isListening) {
          try {
            recognition.start();
            isListening = true;
            micIndicator.style.display = "inline";
            micBtn.disabled = true;
            stopMicBtn.disabled = false;
            micBtn.innerHTML = "🎤 Listening...";
            statusEl.textContent = "Dictation active…";
          } catch (e) {}
        }
      });

      stopMicBtn.addEventListener("click", () => {
        if (isListening) {
          recognition.stop();
        }
      });

      recognition.onresult = (event) => {
        let interim = '';
        for (let i = event.resultIndex; i < event.results.length; i++) {
          if (event.results[i].isFinal) {
            textarea.value += event.results[i][0].transcript + " ";
          } else {
            interim += event.results[i][0].transcript;
          }
        }
      };

      recognition.onend = () => {
        if (isListening) {
          // user didn’t press stop => auto-restart
          try { recognition.start(); } catch (e) {}
        } else {
          micIndicator.style.display = "none";
          micBtn.innerHTML = "🎤 Start Dictation";
          micBtn.disabled = false;
          stopMicBtn.disabled = true;
          statusEl.textContent = "";
        }
      };

      recognition.onerror = (e) => {
        console.error("Speech recognition error:", e.error);
        isListening = false;
        micIndicator.style.display = "none";
        micBtn.disabled = false;
        micBtn.innerHTML = "🎤 Start Dictation";
        stopMicBtn.disabled = true;
        statusEl.textContent = "Dictation error or unsupported browser. You can still type your note.";
      };

      // When user presses Stop
      stopMicBtn.addEventListener("click", () => {
        isListening = false;
        recognition.stop();
      });
    } else {
      micBtn.disabled = true;
      stopMicBtn.disabled = true;
      statusEl.textContent = "Speech recognition not supported in this browser.";
    }

    // ---- Drag & drop X‑ray upload ----
    const dropzone  = document.getElementById('dropzone');
    const input     = document.getElementById('xrayInput');
    const browseBtn = document.getElementById('browseBtn');
    const thumbs    = document.getElementById('thumbs');

    const MAX_FILES = 20;
    const MAX_SIZE  = 20 * 1024 * 1024; // 20MB
    const ACCEPTED  = ['image/png','image/jpeg','image/webp','image/bmp','image/tiff','application/dicom','application/dicom+json','application/dicom+octet-stream'];

    let fileList = []; // custom list to manage removals

    function renderThumbs() {
      thumbs.innerHTML = '';
      fileList.forEach((file, idx) => {
        const div = document.createElement('div');
        div.className = 'thumb';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-outline-danger remove';
        btn.textContent = '×';
        btn.onclick = () => { fileList.splice(idx,1); syncInput(); renderThumbs(); };

        const img = document.createElement('img');
        // For DICOM we show a generic thumbnail placeholder
        if (file.type.startsWith('image/')) {
          const url = URL.createObjectURL(file);
          img.src = url;
          img.onload = () => URL.revokeObjectURL(url);
        } else {
          img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="120" height="100"><rect width="120" height="100" fill="#eef2f7"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="Arial" font-size="12" fill="#6c757d">DICOM</text></svg>');
        }

        div.appendChild(img);
        div.appendChild(btn);
        thumbs.appendChild(div);
      });
    }

    function syncInput() {
      const dt = new DataTransfer();
      fileList.forEach(f => dt.items.add(f));
      input.files = dt.files;
    }

    function validateAndAdd(files) {
      const errs = [];
      for (const f of files) {
        if (fileList.length >= MAX_FILES) { errs.push('Too many files (max '+MAX_FILES+').'); break; }
        if (f.size > MAX_SIZE) { errs.push((f.name||'file')+': exceeds 20MB.'); continue; }

        // If type missing (e.g., some DICOM), allow by extension
        const lower = (f.name||'').toLowerCase();
        const looksDicom = lower.endsWith('.dcm') || lower.endsWith('.dicom');
        if (!ACCEPTED.includes(f.type) && !f.type.startsWith('image/') && !looksDicom) {
          errs.push((f.name||'file')+': unsupported type.');
          continue;
        }
        fileList.push(f);
      }
      if (errs.length) alert(errs.join('\n'));
      syncInput();
      renderThumbs();
    }

    // Click to browse
    browseBtn.addEventListener('click', () => input.click());
    // Picker change
    input.addEventListener('change', (e) => validateAndAdd(e.target.files));

    // Drag & drop
    ;['dragenter','dragover'].forEach(ev => dropzone.addEventListener(ev, e => {
      e.preventDefault(); e.stopPropagation(); dropzone.classList.add('dragover');
    }));
    ;['dragleave','drop'].forEach(ev => dropzone.addEventListener(ev, e => {
      e.preventDefault(); e.stopPropagation(); dropzone.classList.remove('dragover');
    }));
    dropzone.addEventListener('drop', (e) => {
      const files = e.dataTransfer.files;
      if (files && files.length) validateAndAdd(files);
    });
    dropzone.addEventListener('click', () => input.click());

    // Simple client-side form validation
    const form = document.getElementById('noteForm');
    form.addEventListener('submit', (e) => {
      if (!form.checkValidity()) {
        e.preventDefault();
        e.stopPropagation();
      }
      form.classList.add('was-validated');
    });
  </script>
</body>
</html>
