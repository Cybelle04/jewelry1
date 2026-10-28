<?php
// get_hash.php
$password = 'admin123';
$hash = password_hash($password, PASSWORD_DEFAULT);
echo "Password: admin123<br>";
echo "Hash: " . $hash;
echo "<br><br>Copy this hash and use it in the SQL above.";
?>