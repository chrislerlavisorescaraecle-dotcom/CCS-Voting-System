<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}

if (($_SESSION['role'] ?? null) !== 'voter' || !isset($_SESSION['voter_id'], $_SESSION['account_id']) || (int) $_SESSION['voter_id'] !== (int) $_SESSION['account_id']) {
	if (isset($_SESSION['account_id'])) {
		http_response_code(403);
		exit('Only logged-in voter accounts can cast ballots.');
	}

	header('Location: ../landing-page/log-in.php?next=vote.php');
	exit;
}

require_once __DIR__ . '/database/connect.php';

try {
	$voterId = (int) $_SESSION['voter_id'];
	$voterQuery = $conn->prepare("SELECT account_id, fullname FROM accounts WHERE account_id = ? AND role = 'voter' AND status = 'active' LIMIT 1");
	$voterQuery->bind_param('i', $voterId);
	$voterQuery->execute();
	$activeVoter = $voterQuery->get_result()->fetch_assoc();
	$voterQuery->close();
} catch (mysqli_sql_exception $exception) {
	error_log('Voter authorization error: ' . $exception->getMessage());
	$conn->close();
	http_response_code(500);
	exit('Voter access is temporarily unavailable.');
}

if (!$activeVoter) {
	$_SESSION = [];
	session_destroy();
	http_response_code(403);
	exit('This voter account is no longer active. Please contact an administrator.');
}

$_SESSION['voter_name'] = $activeVoter['fullname'];
