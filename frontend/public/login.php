<?php
session_start();
include("db.php");

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = null;
$old_email = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Security check failed. Please try again.";
    } else {
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $old_email = $email;

        $stmt = $pdo->prepare("SELECT id, name, email, password_hash, specialty_slug FROM doctors WHERE email = ?");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && password_verify($pass, $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['doctor_id']   = $row['id'];
            $_SESSION['doctor_name'] = $row['name'];
            $_SESSION['specialty']   = $row['specialty_slug'];
            header("Location: dashboard.php");
            exit;
        } else {
            $error = "Invalid email or password.";
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>RoundsIQ Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body {
      min-height: 100vh;
      background: linear-gradient(180deg, #d35400 50%, #004d4d 50%);
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .login-card {
      max-width: 420px;
      width: 100%;
      border-radius: 1rem;
      background: #fff;
      box-shadow: 0 10px 30px rgba(0,0,0,0.1);
      padding: 2rem;
    }
    .logo {
      display: block;
      margin: 0 auto 1rem;
      width: 80px;
    }
    .btn-primary {
      background-color: #d35400;
      border-color: #d35400;
    }
    .btn-primary:hover {
      background-color: #b84300;
      border-color: #b84300;
    }
  </style>
</head>
<body>

<div class="login-card">
    <img src="images/logo.jpg" alt="RoundsIQ Logo" class="logo">
    <h4 class="text-center mb-4" style="color:#004d4d;">Doctor Portal Login</h4>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <div class="mb-3">
            <label class="form-label">Email address</label>
            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($old_email); ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">Login</button>
    </form>
</div>

</body>
</html>
