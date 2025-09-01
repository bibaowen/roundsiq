<?php
require_once "auth.php";
require_once "db.php";

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Validate case ID
$case_id = (int)($_GET['id'] ?? 0);
if ($case_id <= 0) {
    http_response_code(400);
    exit("Invalid case id.");
}

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Add Doctor Note (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_note') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        header("Location: view_case.php?" . http_build_query(['id'=>$case_id, 'msg'=>'Security check failed.', 'type'=>'error']));
        exit;
    }

    $note_text = trim($_POST['note'] ?? '');
    if ($note_text === '') {
        header("Location: view_case.php?" . http_build_query(['id'=>$case_id, 'msg'=>'Note cannot be empty.', 'type'=>'error']));
        exit;
    }

    if (mb_strlen($note_text) > 5000) {
        $note_text = mb_substr($note_text, 0, 5000);
    }

    $doctor_id   = (int)($_SESSION['doctor_id'] ?? 0);
    $doctor_name = (string)($_SESSION['doctor_name'] ?? '');

    $ins = $pdo->prepare("
        INSERT INTO case_notes (clinical_input_id, doctor_id, doctor_name, note, created_at)
        VALUES (:cid, :did, :dname, :note, NOW())
    ");
    $ins->execute([
        ':cid'   => $case_id,
        ':did'   => $doctor_id ?: null,
        ':dname' => $doctor_name ?: null,
        ':note'  => $note_text
    ]);

    header("Location: view_case.php?" . http_build_query(['id'=>$case_id, 'msg'=>'Note added.', 'type'=>'success']));
    exit;
}

// Load case details
$sql = "
    SELECT ci.id, ci.patient_id, ci.symptoms, ci.history, ci.lab_results, ci.note, ci.created_at,
           p.name AS patient_name, p.age AS patient_age, p.gender AS patient_gender
    FROM clinical_inputs ci
    LEFT JOIN patients p ON p.id = ci.patient_id
    WHERE ci.id = :id
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':id'=>$case_id]);
$case = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$case) {
    http_response_code(404);
    exit("Case not found.");
}

// Latest AI response
$latest = $pdo->prepare("
    SELECT id, clinical_input_id, summary, full_response, created_at
    FROM ai_responses
    WHERE clinical_input_id = :id
    ORDER BY created_at DESC
    LIMIT 1
");
$latest->execute([':id'=>$case_id]);
$ai = $latest->fetch(PDO::FETCH_ASSOC);

// Doctor notes
$notesStmt = $pdo->prepare("
    SELECT id, doctor_id, doctor_name, note, created_at
    FROM case_notes
    WHERE clinical_input_id = :id
    ORDER BY created_at DESC
");
$notesStmt->execute([':id'=>$case_id]);
$notes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);

// Flash messages
$msg  = $_GET['msg']  ?? '';
$type = $_GET['type'] ?? 'success';

// Current doctor
$me_id   = (int)($_SESSION['doctor_id'] ?? 0);
$me_name = (string)($_SESSION['doctor_name'] ?? '');

// Fetch images
$imgs = [];
try {
    $imgStmt = $pdo->prepare("SELECT path FROM clinical_images WHERE clinical_input_id = :id ORDER BY id ASC");
    $imgStmt->execute([':id'=>$case_id]);
    $imgs = $imgStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

if (!$imgs) {
    try {
        $ij = $pdo->prepare("SELECT images_json FROM clinical_inputs WHERE id=:id");
        $ij->execute([':id'=>$case_id]);
        $row = $ij->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['images_json'])) {
            $decoded = json_decode($row['images_json'], true);
            if (is_array($decoded)) $imgs = $decoded;
        }
    } catch (Throwable $e) {}
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>RoundsIQ • View Case #<?= (int)$case_id ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
:root {
    --brand-teal:#004d4d; --brand-orange:#d35400;
    --brand-teal-100:#e6f0f0; --brand-orange-100:#ffe8d9;
}
body { background:#f6f8fb; }
.navbar { background:linear-gradient(90deg,var(--brand-teal),#003838); }
.navbar-brand, .navbar-text { color:#fff !important; }
.logo { height:36px; border-radius:.5rem; background:#fff; padding:.1rem .25rem; margin-right:.5rem; }
.btn-brand { background:var(--brand-orange); border-color:var(--brand-orange); color:#fff; }
.btn-brand:hover { background:#b84300; border-color:#b84300; }
.card-section { border-radius:1rem; border:1px solid #eef2f7; background:#fff; }
.section-title { color:var(--brand-teal); }
.badge-teal { background:var(--brand-teal-100); color:var(--brand-teal); }
.note-card { border-left:4px solid var(--brand-teal); }
</style>
</head>
<body>
<nav class="navbar navbar-expand-lg">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
            <img src="images/logo.jpg" class="logo" alt="RoundsIQ"><span>RoundsIQ</span>
        </a>
        <div class="ms-auto navbar-text">
            Dr. <?= h($me_name) ?>
            <a class="btn btn-sm btn-light ms-3" href="logout.php">Logout</a>
        </div>
    </div>
</nav>

<div class="container my-4">
    <?php if ($msg): ?>
    <div class="alert alert-<?= $type==='error'?'danger':'success' ?> alert-dismissible fade show">
        <?= h($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>Case #<?= (int)$case['id'] ?> <span class="badge badge-teal ms-2"><?= h(date('Y-m-d H:i', strtotime($case['created_at']))) ?></span></h4>
        <div class="btn-group">
            <a class="btn btn-outline-secondary" href="dashboard.php">Back</a>
            <a class="btn btn-outline-secondary" href="case_pdf.php?id=<?= (int)$case['id'] ?>">PDF</a>
            <a class="btn btn-brand" href="reanalyze_case.php?id=<?= (int)$case['id'] ?>">Re-Examine</a>
        </div>
    </div>

    <!-- Patient Info -->
    <div class="card card-section mb-4">
        <div class="card-body">
            <h6 class="section-title">Patient</h6>
            <div class="row">
                <div class="col-md-4"><strong>Name:</strong> <?= h($case['patient_name'] ?? 'Unknown') ?></div>
                <div class="col-md-4"><strong>Age:</strong> <?= h($case['patient_age'] ?? '—') ?></div>
                <div class="col-md-4"><strong>Gender:</strong> <?= h($case['patient_gender'] ?? '—') ?></div>
            </div>
        </div>
    </div>

    <!-- Clinical Notes -->
    <div class="card card-section mb-4">
        <div class="card-body">
            <h6 class="section-title mb-3">Clinical Note</h6>
            <div class="row g-3">
                <div class="col-md-6">
                    <h6 class="text-secondary">Symptoms</h6>
                    <div class="border rounded p-2 bg-light"><?= nl2br(h($case['symptoms'] ?? '')) ?: '<span class="text-muted">—</span>' ?></div>
                </div>
                <div class="col-md-6">
                    <h6 class="text-secondary">History</h6>
                    <div class="border rounded p-2 bg-light"><?= nl2br(h($case['history'] ?? '')) ?: '<span class="text-muted">—</span>' ?></div>
                </div>
                <div class="col-md-6">
                    <h6 class="text-secondary">Lab Results</h6>
                    <div class="border rounded p-2 bg-light"><?= nl2br(h($case['lab_results'] ?? '')) ?: '<span class="text-muted">—</span>' ?></div>
                </div>
                <div class="col-md-6">
                    <h6 class="text-secondary">Free-Text Note</h6>
                    <div class="border rounded p-2 bg-light"><?= nl2br(h($case['note'] ?? '')) ?: '<span class="text-muted">—</span>' ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Images -->
    <?php if ($imgs): ?>
    <div class="card card-section mb-4">
        <div class="card-body">
            <h6 class="section-title">X-ray Images</h6>
            <div class="row g-3">
                <?php foreach ($imgs as $p): ?>
                <div class="col-6 col-md-3">
                    <div class="border rounded p-1 bg-light text-center">
                        <?php if (preg_match('/\.(png|jpe?g|webp|bmp|tiff?)$/i', $p)): ?>
                            <img src="<?= h($p) ?>" alt="X-ray" class="img-fluid rounded">
                        <?php else: ?>
                            <div class="p-3">
                                <div class="small text-muted mb-2">DICOM</div>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= h($p) ?>" target="_blank">Download</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- AI Summary -->
    <div class="card card-section mb-4">
        <div class="card-body">
            <h6 class="section-title">AI Summary</h6>
            <?php if ($ai && !empty($ai['summary'])): ?>
                <div class="p-3 rounded" style="background:#fffaf5;border:1px solid #ffe8d9"><?= nl2br(h($ai['summary'])) ?></div>
                <?php if (!empty($ai['full_response'])): ?>
                    <details class="mt-3">
                        <summary class="link-primary" style="cursor:pointer">View full response</summary>
                        <div class="mt-2 p-3 border rounded bg-light">
                            <pre style="white-space:pre-wrap;"><?= h($ai['full_response']) ?></pre>
                        </div>
                    </details>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-muted">No AI summary yet for this case.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Doctor Review Notes -->
    <div class="card card-section mb-5">
        <div class="card-body">
            <h6 class="section-title mb-3">Doctor Review Notes</h6>
            <form method="post" class="mb-4">
                <input type="hidden" name="action" value="add_note">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <textarea name="note" rows="3" class="form-control mb-2" placeholder="Observations, rationale, follow-up tasks..." required></textarea>
                <div class="text-end">
                    <button class="btn btn-brand">Save Note</button>
                </div>
            </form>
            <?php if (!$notes): ?>
                <p class="text-muted">No notes yet.</p>
            <?php else: ?>
                <?php foreach ($notes as $n):
                    $owned = ($n['doctor_id'] && $n['doctor_id']===$me_id) ||
                             (!$n['doctor_id'] && $n['doctor_name']===$me_name);
                ?>
                <div class="card note-card mb-3">
                    <div class="card-body">
                        <strong><?= h($n['doctor_name'] ?: 'Doctor') ?></strong>
                        <span class="text-muted">• <?= h(date('Y-m-d H:i', strtotime($n['created_at']))) ?></span>
                        <div class="mt-2"><?= nl2br(h($n['note'])) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
