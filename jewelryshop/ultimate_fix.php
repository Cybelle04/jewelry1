<?php
// ultimate_fix.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

echo "<h1>Ultimate Admin Fix Tool</h1>";

// First, check if database connection works
if($db) {
    echo "✓ Database connected successfully<br><br>";
} else {
    echo "✗ Database connection failed<br>";
    exit();
}

// Check if users table exists
$table_check = "SHOW TABLES LIKE 'users'";
$table_stmt = $db->prepare($table_check);
$table_stmt->execute();

if($table_stmt->rowCount() == 0) {
    echo "✗ Users table doesn't exist!<br>";
    echo "Creating tables...<br>";
    
    // Create users table
    $create_users = "CREATE TABLE IF NOT EXISTS users (
        id INT PRIMARY KEY AUTO_INCREMENT,
        username VARCHAR(50) UNIQUE NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        phone VARCHAR(20),
        address TEXT,
        role ENUM('admin', 'user') DEFAULT 'user',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    
    $db->exec($create_users);
    echo "✓ Users table created<br>";
}

// Set new password
$new_password = 'admin123';
$hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

echo "<h2>Setting up Admin User:</h2>";
echo "Password to set: <strong>{$new_password}</strong><br>";
echo "Generated hash: " . $hashed_password . "<br><br>";

// First, delete any existing admin users
$delete_query = "DELETE FROM users WHERE username = 'admin' OR email = 'admin@jewelryshop.com'";
$delete_stmt = $db->prepare($delete_query);
$delete_stmt->execute();
echo "✓ Removed existing admin users<br>";

// Insert fresh admin user
$insert_query = "INSERT INTO users (username, email, password, full_name, role, created_at) 
                 VALUES (:username, :email, :password, :full_name, 'admin', NOW())";
$insert_stmt = $db->prepare($insert_query);
$username = 'admin';
$email = 'admin@jewelryshop.com';
$full_name = 'Administrator';

$insert_stmt->bindParam(':username', $username);
$insert_stmt->bindParam(':email', $email);
$insert_stmt->bindParam(':password', $hashed_password);
$insert_stmt->bindParam(':full_name', $full_name);

if($insert_stmt->execute()) {
    echo "✓ Admin user created successfully!<br><br>";
} else {
    echo "✗ Failed to create admin user<br>";
    print_r($insert_stmt->errorInfo());
    exit();
}

// Verify the user was created
$verify_query = "SELECT * FROM users WHERE username = 'admin'";
$verify_stmt = $db->prepare($verify_query);
$verify_stmt->execute();
$admin = $verify_stmt->fetch(PDO::FETCH_ASSOC);

if($admin) {
    echo "<h3>Verification:</h3>";
    echo "User ID: {$admin['id']}<br>";
    echo "Username: {$admin['username']}<br>";
    echo "Email: {$admin['email']}<br>";
    echo "Role: {$admin['role']}<br>";
    echo "Full Name: {$admin['full_name']}<br>";
    echo "Password Hash: " . substr($admin['password'], 0, 50) . "...<br><br>";
    
    // Test password verification
    if(password_verify($new_password, $admin['password'])) {
        echo "<span style='color:green; font-size:18px;'>✓ PASSWORD VERIFICATION SUCCESSFUL!</span><br>";
        echo "The password '{$new_password}' works correctly.<br><br>";
    } else {
        echo "<span style='color:red; font-size:18px;'>✗ PASSWORD VERIFICATION FAILED!</span><br>";
        echo "This should not happen. Please check your PHP installation.<br><br>";
    }
}

// Test session
echo "<h3>Testing Session:</h3>";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['test'] = 'working';
echo "Session ID: " . session_id() . "<br>";
echo "Session test: " . $_SESSION['test'] . "<br><br>";

// Create a direct login test
echo "<h3>Direct Login Test Form:</h3>";
echo "<form method='POST' action='' style='border:1px solid #ccc; padding:20px; border-radius:5px;'>";
echo "<div style='margin-bottom:10px'>";
echo "<label>Username:</label><br>";
echo "<input type='text' name='test_username' value='admin' style='width:100%; padding:8px; margin-top:5px;'>";
echo "</div>";
echo "<div style='margin-bottom:10px'>";
echo "<label>Password:</label><br>";
echo "<input type='password' name='test_password' value='admin123' style='width:100%; padding:8px; margin-top:5px;'>";
echo "</div>";
echo "<button type='submit' name='test_login' style='background:#007bff; color:white; padding:10px 20px; border:none; border-radius:5px; cursor:pointer;'>Test Login</button>";
echo "</form>";

if(isset($_POST['test_login'])) {
    $test_username = $_POST['test_username'];
    $test_password = $_POST['test_password'];
    
    $test_query = "SELECT * FROM users WHERE username = :username AND role = 'admin'";
    $test_stmt = $db->prepare($test_query);
    $test_stmt->bindParam(':username', $test_username);
    $test_stmt->execute();
    
    if($test_stmt->rowCount() > 0) {
        $test_user = $test_stmt->fetch(PDO::FETCH_ASSOC);
        
        if(password_verify($test_password, $test_user['password'])) {
            echo "<div style='background:#d4edda; color:#155724; padding:15px; margin-top:15px; border-radius:5px; border:1px solid #c3e6cb;'>";
            echo "<strong>✓ LOGIN SUCCESSFUL!</strong><br>";
            echo "Welcome, " . $test_user['full_name'] . "<br>";
            echo "User ID: " . $test_user['id'] . "<br>";
            echo "Role: " . $test_user['role'] . "<br>";
            echo "</div>";
            
            // Set session for testing
            $_SESSION['user_id'] = $test_user['id'];
            $_SESSION['username'] = $test_user['username'];
            $_SESSION['role'] = $test_user['role'];
            $_SESSION['full_name'] = $test_user['full_name'];
            
            echo "<div style='margin-top:15px;'>";
            echo "<a href='admin/simple_dashboard.php' style='display:inline-block; background:#28a745; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Go to Admin Dashboard</a>";
            echo "&nbsp;&nbsp;";
            echo "<a href='admin/simple_login.php' style='display:inline-block; background:#007bff; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Go to Simple Login Page</a>";
            echo "</div>";
        } else {
            echo "<div style='background:#f8d7da; color:#721c24; padding:15px; margin-top:15px; border-radius:5px; border:1px solid #f5c6cb;'>";
            echo "<strong>✗ LOGIN FAILED!</strong><br>";
            echo "Password is incorrect for user: {$test_username}<br>";
            echo "Stored hash: " . substr($test_user['password'], 0, 50) . "...<br>";
            echo "Your password: {$test_password}<br>";
            echo "</div>";
        }
    } else {
        echo "<div style='background:#f8d7da; color:#721c24; padding:15px; margin-top:15px; border-radius:5px; border:1px solid #f5c6cb;'>";
        echo "<strong>✗ LOGIN FAILED!</strong><br>";
        echo "No admin user found with username: {$test_username}<br>";
        echo "</div>";
    }
}

echo "<br><hr>";
echo "<h3>Login Options:</h3>";
echo "<ul>";
echo "<li><a href='admin/simple_login.php' target='_blank'>Simple Admin Login Page</a></li>";
echo "<li><a href='admin/login.php' target='_blank'>Original Admin Login Page</a></li>";
echo "<li><a href='check_db.php' target='_blank'>Database Check Page</a></li>";
echo "</ul>";
?>