<?php
session_start();
require_once "db.php";

if (!isset($_SESSION['doctor_id'])) {
  header("Location: login.php");
  exit;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---- Date filters (defaults: last 7 days) ----
$to   = $_GET['to']   ?? date('Y-m-d');
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-6 days', strtotime($to)));

$from_dt = DateTime::createFromFormat('Y-m-d', $from) ?: new DateTime('-6 days');
$to_dt   = DateTime::createFromFormat('Y-m-d', $to)   ?: new DateTime();
if ($from_dt > $to_dt) { [$from_dt, $to_dt] = [$to_dt, $from_dt]; } // swap if reversed

// ---- Totals (all-time) ----
$totals = $pdo->query("
  SELECT 
    (SELECT COUNT(*) FROM patients) AS total_patients,
    (SELECT COUNT(*) FROM clinical_inputs) AS total_inputs,
    (SELECT COUNT(*) FROM ai_responses) AS total_responses
")->fetch(PDO::FETCH_ASSOC);

// ---- Build an indexed array of all dates in range ----
$labels = [];
$index  = [];
$iter = clone $from_dt;
while ($iter <= $to_dt) {
  $key = $iter->format('Y-m-d');
  $labels[] = $key;
  $index[$key] = 0;
  $iter->modify('+1 day');
}

// ---- Query counts for range & map to labels ----
$chartStmt = $pdo->prepare("
  SELECT DATE(created_at) AS day, COUNT(*) AS count
  FROM clinical_inputs
  WHERE DATE(created_at) BETWEEN :from AND :to
  GROUP BY day
  ORDER BY day ASC
");
$chartStmt->execute([':from'=>$from_dt->format('Y-m-d'), ':to'=>$to_dt->format('Y-m-d')]);
$rows = $chartStmt->fetchAll(PDO::FETCH_ASSOC);

$series_cases = $index; // copy zero-filled
foreach ($rows as $r) {
  $day = $r['day'];
  if (isset($series_cases[$day])) $series_cases[$day] = (int)$r['count'];
}

// (Optional) AI responses per day in range
$aiStmt = $pdo->prepare("
  SELECT DATE(created_at) AS day, COUNT(*) AS count
  FROM ai_responses
  WHERE DATE(created_at) BETWEEN :from AND :to
  GROUP BY day
  ORDER BY day ASC
");
$aiStmt->execute([':from'=>$from_dt->format('Y-m-d'), ':to'=>$to_dt->format('Y-m-d')]);
$aiRows = $aiStmt->fetchAll(PDO::FETCH_ASSOC);

$series_ai = $index; // zero-filled
foreach ($aiRows as $r) {
  $day = $r['day'];
  if (isset($series_ai[$day])) $series_ai[$day] = (int)$r['count'];
}

// ---- JSON for Chart.js ----
$labels_json    = json_encode(array_values($labels));
$cases_json     = json_encode(array_values($series_cases));
$ai_json        = json_encode(array_values($series_ai));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>RoundsIQ • Analytics</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
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
    .card-shell{ border-radius:1rem; border:1px solid #eef2f7; background:#fff; }
    .btn-brand{ background:var(--brand-orange); border-color:var(--brand-orange); color:#fff; }
    .btn-brand:hover{ background:#b84300; border-color:#b84300; }
    .stat-card{ border-radius:1rem; border:1px solid #eef2f7; background:#fff; }
    .chip{ font-size:.75rem; padding:.25rem .5rem; border-radius:999px; }
    .chip-teal{ background:var(--brand-teal-100); color:var(--brand-teal); }
    .chip-orange{ background:var(--brand-orange-100); color:var(--brand-orange); }
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
      <img src="images/logo.jpg" class="logo" alt="RoundsIQ"><span>RoundsIQ</span>
    </a>
    <div class="ms-auto navbar-text">
      Analytics
      <a class="btn btn-sm btn-light ms-3" href="dashboard.php">← Back to Dashboard</a>
    </div>
  </div>
</nav>

<div class="container my-4">
  <!-- Hero -->
  <div class="card card-shell p-4 mb-4">
    <div class="d-lg-flex align-items-center justify-content-between">
      <div>
        <h4 class="mb-1">Analytics Overview</h4>
        <p class="mb-0 text-secondary">Track submissions and RoundsIQ analyses over time.</p>
      </div>
      <form class="row gy-2 gx-2 align-items-end mt-3 mt-lg-0" method="get">
        <div class="col-auto">
          <label class="form-label mb-1">From</label>
          <input type="date" name="from" value="<?= h($from_dt->format('Y-m-d')) ?>" class="form-control">
        </div>
        <div class="col-auto">
          <label class="form-label mb-1">To</label>
          <input type="date" name="to" value="<?= h($to_dt->format('Y-m-d')) ?>" class="form-control">
        </div>
        <div class="col-auto">
          <button class="btn btn-brand">Apply</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Stats -->
  <div class="row g-3 mb-4">
    <div class="col-md-4"><div class="p-4 stat-card">
      <div class="d-flex justify-content-between"><span class="text-secondary">Total Patients</span><span class="chip chip-teal">All time</span></div>
      <div class="display-6 fw-semibold mt-2"><?= (int)$totals['total_patients'] ?></div>
    </div></div>
    <div class="col-md-4"><div class="p-4 stat-card">
      <div class="d-flex justify-content-between"><span class="text-secondary">Clinical Inputs</span><span class="chip chip-orange">All time</span></div>
      <div class="display-6 fw-semibold mt-2"><?= (int)$totals['total_inputs'] ?></div>
    </div></div>
    <div class="col-md-4"><div class="p-4 stat-card">
      <div class="d-flex justify-content-between"><span class="text-secondary">RoundsIQ Responses</span><span class="chip chip-teal">All time</span></div>
      <div class="display-6 fw-semibold mt-2"><?= (int)$totals['total_responses'] ?></div>
    </div></div>
  </div>

  <!-- Chart -->
  <div class="card card-shell p-4">
    <div class="d-flex align-items-center justify-content-between">
      <h5 class="mb-0">Submissions & RoundsIQ Analyses</h5>
      <small class="text-secondary">
        Range: <?= h($from_dt->format('Y-m-d')) ?> → <?= h($to_dt->format('Y-m-d')) ?>
      </small>
    </div>
    <canvas id="casesChart" height="92" class="mt-3"></canvas>
  </div>
</div>

<script>
  const labels = <?= $labels_json ?>;
  const dataCases = <?= $cases_json ?>;
  const dataAI = <?= $ai_json ?>;

  // Brand colors
  const teal = '#004d4d';
  const tealFill = 'rgba(0, 77, 77, 0.08)';
  const orange = '#d35400';
  const orangeFill = 'rgba(211, 84, 0, 0.10)';

  const ctx = document.getElementById('casesChart').getContext('2d');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [
        {
          label: 'Clinical Inputs',
          data: dataCases,
          borderColor: teal,
          backgroundColor: tealFill,
          fill: true,
          tension: 0.3,
          borderWidth: 2,
          pointRadius: 3,
          pointHoverRadius: 5
        },
        {
          label: 'AI Responses',
          data: dataAI,
          borderColor: orange,
          backgroundColor: orangeFill,
          fill: true,
          tension: 0.3,
          borderWidth: 2,
          pointRadius: 3,
          pointHoverRadius: 5
        }
      ]
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top' }
      },
      scales: {
        x: { grid: { display: false } },
        y: { beginAtZero: true, ticks: { precision: 0 } }
      }
    }
  });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
