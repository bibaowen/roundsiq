<?php
require_once "auth.php";
require_once "db.php";

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Filters
$q         = trim($_GET['q'] ?? '');
$date_from = $_GET['from'] ?? '';
$date_to   = $_GET['to'] ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 10;
$offset    = ($page - 1) * $perPage;

// WHERE clause
$where  = [];
$params = [];

if ($q !== '') {
    $where[] = "(p.name LIKE :q OR ci.note LIKE :q OR ci.symptoms LIKE :q OR ci.history LIKE :q OR ci.lab_results LIKE :q)";
    $params[':q'] = "%{$q}%";
}
if ($date_from !== '') { $where[] = "DATE(ci.created_at) >= :from"; $params[':from'] = $date_from; }
if ($date_to   !== '') { $where[] = "DATE(ci.created_at) <= :to";   $params[':to']   = $date_to; }

$whereSql = $where ? "WHERE ".implode(" AND ", $where) : "";

// Stats
$totalsSql = "
    SELECT
      (SELECT COUNT(*) FROM clinical_inputs ci $whereSql) AS cases_total,
      (SELECT COUNT(DISTINCT ar.clinical_input_id)
         FROM ai_responses ar
         JOIN clinical_inputs ci2 ON ci2.id = ar.clinical_input_id
         ".($whereSql ? preg_replace('/\bci\b/', 'ci2', " $whereSql") : "")."
      ) AS ai_total,
      (SELECT COUNT(DISTINCT p.id)
         FROM patients p
         JOIN clinical_inputs ci3 ON ci3.patient_id = p.id
         $whereSql
      ) AS patients_total
";
$totals = $pdo->prepare($totalsSql);
$totals->execute($params);
$stats = $totals->fetch(PDO::FETCH_ASSOC) ?: ["cases_total"=>0,"ai_total"=>0,"patients_total"=>0];

// Past Cases
$listSql = "
    SELECT
      ci.id AS case_id,
      ci.created_at,
      ci.note, ci.symptoms, ci.history, ci.lab_results,
      p.id AS patient_id, p.name AS patient_name,
      (SELECT ar.summary
         FROM ai_responses ar
         WHERE ar.clinical_input_id = ci.id
         ORDER BY ar.created_at DESC
         LIMIT 1) AS ai_summary
    FROM clinical_inputs ci
    LEFT JOIN patients p ON p.id = ci.patient_id
    $whereSql
    ORDER BY ci.created_at DESC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($listSql);
foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pagination count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM clinical_inputs ci $whereSql");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>RoundsIQ • Doctor Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
:root{
    --brand-teal:#004d4d;
    --brand-orange:#d35400;
    --brand-teal-100:#e6f0f0;
    --brand-orange-100:#ffe8d9;
}
body{ background:#f6f8fb; }
.navbar{ background:linear-gradient(90deg,var(--brand-teal),#003838); }
.navbar-brand,.navbar-text{ color:#fff !important; }
.logo{ height:36px; width:auto; border-radius:.5rem; background:#fff; padding:.1rem .25rem; margin-right:.5rem; }
.card-hero{ background:linear-gradient(135deg,var(--brand-orange),#f07c29); color:#fff; border:none; border-radius:1rem; }
.btn-brand{ background:var(--brand-orange); border-color:var(--brand-orange); }
.btn-brand:hover{ background:#b84300; border-color:#b84300; }
.stat-card{ border-radius:1rem; border:1px solid #eef2f7; background:#fff; }
.chip{ font-size:.75rem; padding:.25rem .5rem; border-radius:999px; }
.chip-teal{ background:var(--brand-teal-100); color:var(--brand-teal); }
.chip-orange{ background:var(--brand-orange-100); color:var(--brand-orange); }
.link-teal{ color:var(--brand-teal); text-decoration:none; }
.link-teal:hover{ text-decoration:underline; }
</style>
</head>
<body>
<nav class="navbar navbar-expand-lg">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center" href="#">
      <img src="images/logo.jpg" class="logo" alt="RoundsIQ">
      <span>RoundsIQ</span>
    </a>
    <div class="ms-auto navbar-text">
      Welcome, Dr. <?= h($_SESSION['doctor_name'] ?? '—'); ?>
      <a class="btn btn-sm btn-light ms-3" href="logout.php">Logout</a>
    </div>
  </div>
</nav>

<div class="container my-4">
  <!-- Hero -->
  <div class="card card-hero mb-4">
    <div class="card-body d-lg-flex align-items-center justify-content-between">
      <div>
        <h4 class="mb-1">Doctor Dashboard</h4>
        <p class="mb-0">Submit notes, revisit past cases, and re-examine with RoundsIQ.</p>
      </div>
      <div class="mt-3 mt-lg-0">
        <a href="index.php" class="btn btn-light me-2">New Clinical Note</a>
        <a href="analytics.php" class="btn btn-outline-light">View Analytics</a>
      </div>
    </div>
  </div>

  <!-- Stats -->
  <div class="row g-3 mb-4">
    <div class="col-md-4"><div class="p-4 stat-card">
      <div class="d-flex justify-content-between"><span class="text-secondary">Total Cases</span><span class="chip chip-orange">All</span></div>
      <div class="display-6 fw-semibold mt-2"><?= (int)$stats['cases_total'] ?></div>
    </div></div>
    <div class="col-md-4"><div class="p-4 stat-card">
      <div class="d-flex justify-content-between"><span class="text-secondary">AI Analyses</span><span class="chip chip-teal">Latest</span></div>
      <div class="display-6 fw-semibold mt-2"><?= (int)$stats['ai_total'] ?></div>
    </div></div>
    <div class="col-md-4"><div class="p-4 stat-card">
      <div class="d-flex justify-content-between"><span class="text-secondary">Patients Seen</span><span class="chip chip-teal">Unique</span></div>
      <div class="display-6 fw-semibold mt-2"><?= (int)$stats['patients_total'] ?></div>
    </div></div>
  </div>

  <!-- Filters -->
  <div class="card mb-3">
    <div class="card-body">
      <form class="row gy-2 gx-3 align-items-end" method="get">
        <div class="col-md-5">
          <label class="form-label">Search</label>
          <input type="text" name="q" value="<?= h($q) ?>" class="form-control"
                 placeholder="Patient name or keywords (note/symptoms/history/labs)">
        </div>
        <div class="col-md-3">
          <label class="form-label">From</label>
          <input type="date" name="from" value="<?= h($date_from) ?>" class="form-control">
        </div>
        <div class="col-md-3">
          <label class="form-label">To</label>
          <input type="date" name="to" value="<?= h($date_to) ?>" class="form-control">
        </div>
        <div class="col-md-1">
          <button class="btn btn-brand w-100">Filter</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Past Cases -->
  <div class="card shadow-sm">
    <div class="card-header bg-white"><h5 class="mb-0">Past Cases</h5></div>
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead class="bg-light">
          <tr>
            <th>Patient</th>
            <th width="34%">Clinical Note</th>
            <th>RoundsIQ Summary</th>
            <th>Created</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="5" class="text-center py-4 text-secondary">No cases found.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td>
                <a class="link-teal" href="patient.php?id=<?= (int)$r['patient_id'] ?>">
                  <?= h($r['patient_name'] ?? 'Unknown') ?>
                </a>
              </td>
              <td class="text-secondary">
                <?php
                  $snippet = $r['note'] ?: ($r['symptoms'] ?: ($r['history'] ?: $r['lab_results']));
                  echo nl2br(h(mb_strimwidth((string)$snippet, 0, 160, '…')));
                ?>
              </td>
              <td class="text-secondary">
                <?= $r['ai_summary'] ? h(mb_strimwidth($r['ai_summary'], 0, 120, '…')) : '<span class="text-muted">—</span>' ?>
              </td>
              <td><?= h(date('Y-m-d H:i', strtotime($r['created_at']))) ?></td>
              <td class="text-end">
                <div class="btn-group">
                  <a class="btn btn-sm btn-outline-secondary" href="view_case.php?id=<?= (int)$r['case_id'] ?>">View</a>
                  <a class="btn btn-sm btn-brand" href="reanalyze_case.php?id=<?= (int)$r['case_id'] ?>">Re-Examine</a>
                  <a class="btn btn-sm btn-outline-secondary" href="case_pdf.php?id=<?= (int)$r['case_id'] ?>">PDF</a>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="card-body">
      <nav>
        <ul class="pagination justify-content-end mb-0">
          <?php
          $qs = $_GET;
          for ($i=1; $i<=$totalPages; $i++){
            $qs['page'] = $i;
            $url = '?'.http_build_query($qs);
            $active = $i===$page ? 'active' : '';
            echo "<li class='page-item $active'><a class='page-link' href='$url'>$i</a></li>";
          }
          ?>
        </ul>
      </nav>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
