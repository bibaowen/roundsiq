<?php
include("auth.php");
include("db.php");
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$sql = "
  SELECT p.name, p.age, p.gender, 
         c.symptoms, c.history, c.lab_results, c.created_at,
         r.summary, r.differential_diagnosis, r.suggested_tests, 
         r.management_plan, r.tips, r.follow_up_recommendations
  FROM clinical_inputs c
  JOIN patients p ON p.id = c.patient_id
  JOIN ai_responses r ON r.clinical_input_id = c.id
  WHERE c.id = $id
";

$query = $conn->query($sql);

if (!$query) {
    echo "<h3 class='text-danger'>Query failed: " . $conn->error . "</h3>";
    echo "<p><code>$sql</code></p>";
    exit;
}

$data = $query->fetch_assoc();
if (!$data) {
    echo "<h3>No report found for this ID.</h3>";
    echo "<p><a href='history.php'>Back to history</a></p>";
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Diagnostic Report</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container mt-5">

  <h2 class="mb-4 text-primary">AI Diagnostic Report</h2>

  <p><strong>Date:</strong> <?= htmlspecialchars($data['created_at']) ?></p>
  <p><strong>Patient:</strong> <?= htmlspecialchars($data['name']) ?>, <?= htmlspecialchars($data['age']) ?> years, <?= htmlspecialchars($data['gender']) ?></p>

  <hr>

  <h4>Clinical Input</h4>
  <p><strong>Symptoms:</strong><br><?= nl2br(htmlspecialchars($data['symptoms'])) ?></p>
  <p><strong>History:</strong><br><?= nl2br(htmlspecialchars($data['history'])) ?></p>
  <p><strong>Lab Results:</strong><br><?= nl2br(htmlspecialchars($data['lab_results'])) ?></p>

  <hr>

  <h4>AI-Generated Diagnostic Response</h4>
  <p><strong>Summary:</strong><br><?= nl2br(htmlspecialchars($data['summary'])) ?></p>
  <p><strong>Differential Diagnosis:</strong><br><?= nl2br(htmlspecialchars($data['differential_diagnosis'])) ?></p>
  <p><strong>Suggested Tests:</strong><br><?= nl2br(htmlspecialchars($data['suggested_tests'])) ?></p>
  <p><strong>Management Plan:</strong><br><?= nl2br(htmlspecialchars($data['management_plan'])) ?></p>
  <p><strong>Tips:</strong><br><?= nl2br(htmlspecialchars($data['tips'])) ?></p>
  <p><strong>Follow-up Recommendations:</strong><br><?= nl2br(htmlspecialchars($data['follow_up_recommendations'])) ?></p>

  <hr>

  <a href="generate_pdf.php?id=<?= $id ?>" class="btn btn-success">Download PDF</a>
  <a href="history.php" class="btn btn-secondary">← Back to History</a>

</body>
</html>
