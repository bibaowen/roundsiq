<?php
require_once "auth.php";
ini_set('max_execution_time', 300); // 300 seconds = 5 minutes
require_once "db.php";

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function back_to_view($id, $msg='', $type='success'){
  header("Location: view_case.php?" . http_build_query(['id'=>$id,'msg'=>$msg,'type'=>$type]));
  exit;
}

$case_id = (int)($_GET['id'] ?? 0);
if ($case_id <= 0) { http_response_code(400); exit("Invalid case id."); }

// ---- Load the clinical input (note sections) ----
$stmt = $pdo->prepare("SELECT id, symptoms, history, lab_results, note FROM clinical_inputs WHERE id = :id LIMIT 1");
$stmt->execute([':id'=>$case_id]);
$case = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$case) back_to_view($case_id, "Case not found.", "error");

// ---- Gather stored images ----
$uploaded_paths = [];
try {
  $imgStmt = $pdo->prepare("SELECT path FROM clinical_images WHERE clinical_input_id = :id ORDER BY id ASC");
  $imgStmt->execute([':id'=>$case_id]);
  $uploaded_paths = $imgStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
  // ignore; fallback below
}
if (!$uploaded_paths) {
  try {
    $ij = $pdo->prepare("SELECT images_json FROM clinical_inputs WHERE id=:id");
    $ij->execute([':id'=>$case_id]);
    $row = $ij->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['images_json'])) {
      $decoded = json_decode($row['images_json'], true);
      if (is_array($decoded)) $uploaded_paths = $decoded;
    }
  } catch (Throwable $e) {}
}

// ---- Build note payload ----
$payload_note = trim(
  "Symptoms: ".($case['symptoms'] ?? '')."\n".
  "History: ".($case['history'] ?? '')."\n".
  "Lab Results: ".($case['lab_results'] ?? '')."\n".
  "Note: ".($case['note'] ?? '')
);
$specialty = $_SESSION['specialty'] ?? 'general';

// ---- Call Flask AI API ----
$apiUrl  = "http://localhost:5000/analyze";
$apiKey  = "abc123xyzsecure"; // replace in production

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

// If images exist, send multipart; otherwise send JSON
if (!empty($uploaded_paths)) {
  $postFields = [
    'note'      => $payload_note,
    'specialty' => $specialty
  ];
  foreach ($uploaded_paths as $idx => $rel) {
    $abs = __DIR__ . '/' . ltrim($rel, '/');
    if (is_file($abs)) {
      $mime = mime_content_type($abs) ?: 'application/octet-stream';
      $postFields["images[$idx]"] = new CURLFile($abs, $mime, basename($abs));
    }
  }
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-KEY: '.$apiKey]);
} else {
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['note'=>$payload_note, 'specialty'=>$specialty]));
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-KEY: '.$apiKey]);
}

$response = curl_exec($ch);
$curlErr  = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) back_to_view($case_id, "AI request failed: $curlErr", "error");
if ($httpCode < 200 || $httpCode >= 300) back_to_view($case_id, "AI server responded with HTTP $httpCode.", "error");

// ---- Parse AI result ----
$data = json_decode($response, true);
if (!is_array($data)) back_to_view($case_id, "AI response was not valid JSON.", "error");

$summary       = trim((string)($data['summary'] ?? ''));
$full_response = trim((string)($data['full_response'] ?? ($data['fullResponse'] ?? '')));

if ($summary === '' && $full_response !== '') $summary = mb_substr($full_response, 0, 600);
if ($summary === '' && $full_response === '') back_to_view($case_id, "AI returned an empty result.", "error");

// ---- Insert new ai_responses row ----
$ins = $pdo->prepare("
  INSERT INTO ai_responses (clinical_input_id, summary, full_response, created_at)
  VALUES (:cid, :sum, :full, NOW())
");
$ins->execute([
  ':cid'  => $case_id,
  ':sum'  => $summary,
  ':full' => $full_response
]);

back_to_view($case_id, "Re‑examination completed successfully.");
