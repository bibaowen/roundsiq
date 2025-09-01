<?php
require_once "auth.php";
require_once "db.php";

function back($case_id, $msg='', $type='success'){
  header('Location: view_case.php?'.http_build_query(['id'=>$case_id,'msg'=>$msg,'type'=>$type]));
  exit;
}

$csrf    = $_POST['csrf_token'] ?? '';
if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) back((int)($_POST['case_id'] ?? 0), 'Security check failed.', 'error');

$note_id = (int)($_POST['id'] ?? 0);
$case_id = (int)($_POST['case_id'] ?? 0);
if ($note_id<=0 || $case_id<=0) back($case_id, 'Invalid request.', 'error');

$me_id   = (int)($_SESSION['doctor_id'] ?? 0);
$me_name = (string)($_SESSION['doctor_name'] ?? '');

// Load note
$stmt = $pdo->prepare("SELECT id, clinical_input_id, doctor_id, doctor_name FROM case_notes WHERE id=:id LIMIT 1");
$stmt->execute([':id'=>$note_id]);
$note = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$note || (int)$note['clinical_input_id'] !== $case_id) back($case_id, 'Note not found.', 'error');

// Permission
$owned = ($note['doctor_id'] && (int)$note['doctor_id']===$me_id) ||
         (!$note['doctor_id'] && $note['doctor_name']!=='' && $note['doctor_name']===$me_name);
if (!$owned) back($case_id, 'You do not have permission to delete this note.', 'error');

// Delete
$del = $pdo->prepare("DELETE FROM case_notes WHERE id=:id");
$del->execute([':id'=>$note_id]);

back($case_id, 'Note deleted.');
