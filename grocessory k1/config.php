<?php
// Start session for user authentication
session_start();

// Database credentials
$host = 'localhost:3306'; 
$username = 'root';       
$password = '';            
$database = 'grocery_budget_db';

// Create database connection
$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to handle special characters
$conn->set_charset("utf8mb4");

/**
 * Check if user is logged in
 * Redirect to login page if not authenticated
 */
function checkLogin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit();
    }
}

/**
 * Get current logged in user info
 * @return array|null User data or null if not logged in
 */
function getCurrentUser() {
    if (isset($_SESSION['user_id'])) {
        return [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['user_name'],
            'email' => $_SESSION['user_email']
        ];
    }
    return null;
}
?>
