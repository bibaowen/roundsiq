<!-- register.php -->
<!DOCTYPE html>
<html>
<head>
  <title>Doctor Registration</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="container mt-5">
  <h2>Register as Doctor</h2>
  <form method="post" action="register_submit.php">
    <div class="mb-3">
      <label for="name" class="form-label">Name</label>
      <input type="text" name="name" class="form-control" required>
    </div>
    <div class="mb-3">
      <label for="email" class="form-label">Email</label>
      <input type="email" name="email" class="form-control" required>
    </div>
    <div class="mb-3">
      <label for="password" class="form-label">Password</label>
      <input type="password" name="password" class="form-control" required>
    </div>
    <div class="mb-3">
      <label for="specialty" class="form-label">Specialty</label>
      <select name="specialty_slug" class="form-control" required>
      
      <option value=""></option>
        <?php
        include("db.php");
        $result = $conn->query("SELECT slug, name FROM specialties ORDER BY name");
        while ($row = $result->fetch_assoc()) {
            echo "<option value=\"{$row['slug']}\">" . htmlspecialchars($row['name']) . "</option>";
        }
        ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary">Register</button>
  </form>
</body>
</html>