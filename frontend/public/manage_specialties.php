<?php
include("auth.php");
include("db.php");

// Handle Add/Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $name = $conn->real_escape_string($_POST['name']);
    $slug = $conn->real_escape_string($_POST['slug']);
    $prompt = $conn->real_escape_string($_POST['prompt_modifier']);

    if ($id) {
        $conn->query("UPDATE specialties SET name='$name', slug='$slug', prompt_modifier='$prompt' WHERE id=$id");
    } else {
        $conn->query("INSERT INTO specialties (name, slug, prompt_modifier) VALUES ('$name', '$slug', '$prompt')");
    }

    header("Location: manage_specialties.php");
    exit;
}

// Handle Delete
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    $conn->query("DELETE FROM specialties WHERE id = $del_id");
    header("Location: manage_specialties.php");
    exit;
}

// Fetch specialties
$res = $conn->query("SELECT * FROM specialties ORDER BY name ASC");
$specialties = [];
while ($row = $res->fetch_assoc()) {
    $specialties[] = $row;
}

// Edit form
$edit = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $edit_res = $conn->query("SELECT * FROM specialties WHERE id = $edit_id");
    $edit = $edit_res->fetch_assoc();
}
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Manage Specialties</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
  <h2 class="mb-4">⚙️ Manage Medical Specia
