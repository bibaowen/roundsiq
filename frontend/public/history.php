<?php
include("db.php");

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

$query = $conn->query("
  SELECT p.name, c.id, c.created_at 
  FROM clinical_inputs c 
  JOIN patients p ON p.id = c.patient_id 
  WHERE p.name LIKE '%$search%'
  ORDER BY c.created_at DESC
");
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Past Diagnostic History</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container mt-5">

  <h2 class="mb-4">Patient Diagnostic History</h2>

  <form method="get" class="row g-3 mb-4">
    <div class="col-md-6">
      <input type="text" name="search" class="form-control" placeholder="Search by patient name" value="<?= htmlspecialchars($search) ?>">
    </div>
    <div class="col-md-6">
      <button type="submit" class="btn btn-primary">Search</button>
      <a href="history.php" class="btn btn-secondary">Clear</a>
    </div>
  </form>

  <table class="table table-bordered">
    <thead>
      <tr>
        <th>Patient Name</th>
        <th>Date</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
    <?php if ($query->num_rows > 0): ?>
      <?php while ($row = $query->fetch_assoc()): ?>
        <tr>
          <td><?= htmlspecialchars($row['name']) ?></td>
          <td><?= htmlspecialchars($row['created_at']) ?></td>
          <td><a href="view_report.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-primary">View</a></td>
        </tr>
      <?php endwhile; ?>
    <?php else: ?>
      <tr><td colspan="3">No records found.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

</body>
</html>
