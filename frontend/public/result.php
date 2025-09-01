<?php
 include("auth.php");
include("db.php");
$id = $_GET['id'];

$res = $conn->query("SELECT p.name, p.age, p.gender, c.symptoms, r.summary 
                     FROM clinical_inputs c
                     JOIN patients p ON p.id = c.patient_id
                     JOIN ai_responses r ON r.clinical_input_id = c.id
                     WHERE c.id = $id");

$row = $res->fetch_assoc();
?>

<h2>AI Diagnostic Summary for <?= $row['name'] ?></h2>
<p><strong>Age:</strong> <?= $row['age'] ?>, <strong>Gender:</strong> <?= $row['gender'] ?></p>
<p><strong>Symptoms:</strong><br><?= nl2br($row['symptoms']) ?></p>
<h3>AI Response:</h3>
<p><?= nl2br($row['summary']) ?></p>
