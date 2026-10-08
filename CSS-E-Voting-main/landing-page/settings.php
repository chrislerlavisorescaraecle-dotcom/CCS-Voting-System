<?php
require_once __DIR__ . '/../voter-auth-guard.php';

if (!isset($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfToken = $_POST['csrf_token'] ?? '';
	$currentPassword = $_POST['current_password'] ?? '';
	$newPassword = $_POST['new_password'] ?? '';
	$confirmPassword = $_POST['confirm_password'] ?? '';

	if (!is_string($csrfToken) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
		$error = 'Your session expired. Reload the page and try again.';
	} elseif (!is_string($currentPassword) || !is_string($newPassword) || !is_string($confirmPassword)) {
		$error = 'Enter valid password values.';
	} elseif (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
		$error = 'Your new password must be between 8 and 72 characters.';
	} elseif ($newPassword !== $confirmPassword) {
		$error = 'The new password and confirmation do not match.';
	} else {
		try {
			$accountId = (int) $_SESSION['voter_id'];
			$passwordQuery = $conn->prepare("SELECT password FROM accounts WHERE account_id = ? AND role = 'voter' AND status = 'active' LIMIT 1");
			$passwordQuery->bind_param('i', $accountId);
			$passwordQuery->execute();
			$account = $passwordQuery->get_result()->fetch_assoc();
			$passwordQuery->close();

			if (!$account || !password_verify($currentPassword, $account['password'])) {
				$error = 'Your current password is incorrect.';
			} elseif (password_verify($newPassword, $account['password'])) {
				$error = 'Choose a new password that is different from your current password.';
			} else {
				$newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
				$update = $conn->prepare("UPDATE accounts SET password = ? WHERE account_id = ? AND role = 'voter' AND status = 'active'");
				$update->bind_param('si', $newPasswordHash, $accountId);
				$update->execute();
				$updated = $update->affected_rows;
				$update->close();

				if ($updated !== 1) {
					$error = 'Your password could not be changed. Please try again.';
				} else {
					session_regenerate_id(true);
					$conn->close();
					header('Location: settings.php?password_changed=1');
					exit;
				}
			}
		} catch (mysqli_sql_exception $exception) {
			error_log('Voter password update error: ' . $exception->getMessage());
			$error = 'Password settings are temporarily unavailable. Please try again.';
		}
	}
}

function escape(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="theme-color" content="#f4fbfa">
	<title>Account settings | CCS E-Voting System</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
	<link rel="stylesheet" href="../front_end/style.css?v=13">
	<script src="../front_end/script.js?v=7" defer></script>
</head>
<body class="auth-page">
	<header class="site-header auth-header">
		<a class="brand" href="index.php" aria-label="CCS E-Voting System home">
			<img class="brand-seal" src="../image/logo-css.jpg" alt="College of Computer Studies seal">
			<span class="brand-copy"><strong>CCS E-VOTING SYSTEM</strong><small>COLLEGE OF COMPUTER STUDIES</small></span>
		</a>
		<a class="auth-back" href="index.php"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back to home</a>
	</header>
	<main class="settings-layout">
		<section class="auth-panel settings-panel" aria-labelledby="settings-title">
			<div class="auth-heading">
				<p class="eyebrow">ACCOUNT SETTINGS</p>
				<h2 id="settings-title">Change your password</h2>
				<p>Update the password you use to sign in to your voter account.</p>
			</div>
			<?php if (isset($_GET['password_changed'])): ?><p class="auth-notice auth-success" role="status">Your password has been changed successfully.</p><?php endif; ?>
			<?php if ($error !== ''): ?><p class="auth-notice auth-error" role="alert"><?= escape($error) ?></p><?php endif; ?>
			<form class="auth-form js-validated-form" method="post" action="settings.php" novalidate>
				<input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
				<div class="auth-field">
					<label for="current-password">Current password</label>
					<input id="current-password" name="current_password" type="password" autocomplete="current-password" required>
				</div>
				<div class="auth-field">
					<label for="new-password">New password</label>
					<input id="new-password" name="new_password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required>
				</div>
				<div class="auth-field">
					<label for="confirm-password">Confirm new password</label>
					<input id="confirm-password" name="confirm_password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required>
				</div>
				<button class="auth-submit" type="submit">Save new password <i class="ti ti-lock-check" aria-hidden="true"></i></button>
			</form>
			<p class="auth-footnote">For your security, your current password is required before making this change.</p>
		</section>
	</main>
</body>
</html>
