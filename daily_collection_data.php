<?php
// Set the content type to application/json for API-like response
header('Content-Type: application/json');

// Include necessary files
require_once 'includes/headx.php'; // Contains settings and configurations
require_once 'config/dbconnection.php'; // Database connection
require_once "includes/classes/admin-class.php"; // Admins class for data fetching

// Create a new database connection
$dbh = new Dbconnect();

// Instantiate the Admins class
$admins = new Admins($dbh);

// Fetch daily collection data using the new method
$data = $admins->fetchDailyCollection();

// Encode the fetched data into JSON format and output it
echo json_encode($data);
