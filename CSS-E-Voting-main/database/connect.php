<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$db_host = 'localhost';
$db_username = 'root';
$db_password = '';
$db_name = 'css_voting_system';

try {
	$conn = new mysqli($db_host, $db_username, $db_password, $db_name);
	$conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $exception) {
	error_log('Database connection error: ' . $exception->getMessage());
	http_response_code(500);
	exit('The voting system is temporarily unavailable. Please try again later.');
}
