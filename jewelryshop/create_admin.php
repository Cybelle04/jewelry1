<?php
// create_admin.php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Set your admin credentials
$username = 'admin';
$email = 'admin@jewelryshop.com';
$password = 'admin123'; // Change this to your desired password
$full_name = 'Administrator';

// Generate secure password hash
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Check if admin already exists
$check_query = "SELECT id FROM users WHERE username = :username";
$check_stmt = $db->prepare($check_query);
$check_stmt->bindParam(':username', $username);
$check_stmt->execute();

if($check_stmt->rowCount() > 0) {
    // Update existing admin
    $query = "UPDATE users SET password = :password, email = :email, full_name = :full_name WHERE username = :username";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':password', $hashed_password);
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':full_name', $full_name);
    $stmt->bindParam(':username', $username);
    
    if($stmt->execute()) {
        echo "Admin password updated successfully!<br>";
        echo "============================<br>";
        echo "Username: admin<br>";
        echo "Password: admin123<br>";
        echo "============================<br>";
    }
} else {
    // Insert new admin
    $query = "INSERT INTO users (username, email, password, full_name, role) 
              VALUES (:username, :email, :password, :full_name, 'admin')";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':username', $username);
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':password', $hashed_password);
    $stmt->bindParam(':full_name', $full_name);
    
    if($stmt->execute()) {
        echo "Admin user created successfully!<br>";
        echo "============================<br>";
        echo "Username: admin<br>";
        echo "Password: admin123<br>";
        echo "Email: admin@jewelryshop.com<br>";
        echo "============================<br>";
    } else {
        echo "Error creating admin user!";
    }
}

// Display all admin users
echo "<br><br>Current Admin Users:<br>";
$query = "SELECT id, username, email, full_name, created_at FROM users WHERE role = 'admin'";
$stmt = $db->prepare($query);
$stmt->execute();
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

if(count($admins) > 0) {
    foreach($admins as $admin) {
        echo "ID: {$admin['id']} | Username: {$admin['username']} | Email: {$admin['email']}<br>";
    }
} else {
    echo "No admin users found.";
}
?>