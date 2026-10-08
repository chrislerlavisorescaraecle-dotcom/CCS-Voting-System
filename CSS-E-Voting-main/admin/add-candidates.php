<?php
require_once __DIR__ . '/../admin-auth-guard.php';
require_once __DIR__ . '/../database/connect.php';

if (!isset($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function escape(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function lockElectionRosterForEditing(mysqli $conn, int $electionId): ?array
{
	$electionQuery = $conn->prepare('SELECT election_id, election_name, status, (start_date > NOW() AND end_date > NOW()) AS is_future, (start_date <= NOW() AND end_date > NOW()) AS is_ongoing FROM elections WHERE election_id = ? FOR UPDATE');
	$electionQuery->bind_param('i', $electionId);
	$electionQuery->execute();
	$election = $electionQuery->get_result()->fetch_assoc();
	$electionQuery->close();

	if (!$election || !(($election['status'] === 'scheduled' && (int) $election['is_future'] === 1)
		|| ($election['status'] === 'ongoing' && (int) $election['is_ongoing'] === 1))) {
		return null;
	}

	$voteQuery = $conn->prepare('SELECT COUNT(*) AS vote_total FROM votes WHERE election_id = ?');
	$voteQuery->bind_param('i', $electionId);
	$voteQuery->execute();
	$hasVotes = (int) $voteQuery->get_result()->fetch_assoc()['vote_total'] > 0;
	$voteQuery->close();

	return $hasVotes ? null : $election;
}

$error = '';
$selectedElectionId = filter_input(INPUT_GET, 'election_id', FILTER_VALIDATE_INT) ?: 0;
$positionId = 0;
$voterAccountId = 0;

if (isset($_GET['ajax']) && $_GET['ajax'] === 'voter-search') {
	header('Content-Type: application/json; charset=utf-8');
	$query = $_GET['q'] ?? '';
	if (!is_string($query) || strlen(trim($query)) < 2 || strlen($query) > 100) {
		echo json_encode(['results' => []]);
		$conn->close();
		exit;
	}

	try {
		$searchTerm = '%' . trim($query) . '%';
		$search = $conn->prepare("SELECT account_id, fullname, student_id FROM accounts WHERE role = 'voter' AND status = 'active' AND student_id IS NOT NULL AND student_id <> '' AND (fullname LIKE ? OR student_id LIKE ?) ORDER BY fullname LIMIT 10");
		$search->bind_param('ss', $searchTerm, $searchTerm);
		$search->execute();
		$results = $search->get_result()->fetch_all(MYSQLI_ASSOC);
		$search->close();
		echo json_encode(['results' => $results], JSON_THROW_ON_ERROR);
	} catch (mysqli_sql_exception|JsonException $exception) {
		error_log('Candidate voter search error: ' . $exception->getMessage());
		http_response_code(500);
		echo json_encode(['error' => 'Voter search is temporarily unavailable.']);
	}
	$conn->close();
	exit;
}

require_once __DIR__ . '/../database/sync-election-statuses.php';
syncElectionStatuses($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfToken = $_POST['csrf_token'] ?? '';
	$postedAction = $_POST['action'] ?? 'add_candidate';
	$action = is_string($postedAction) ? $postedAction : '';
	$electionId = filter_input(INPUT_POST, 'election_id', FILTER_VALIDATE_INT) ?: 0;
	$positionId = filter_input(INPUT_POST, 'position_id', FILTER_VALIDATE_INT) ?: 0;
	$voterAccountId = filter_input(INPUT_POST, 'voter_account_id', FILTER_VALIDATE_INT) ?: 0;
	$candidateId = filter_input(INPUT_POST, 'candidate_id', FILTER_VALIDATE_INT) ?: 0;
	$postedPositionName = $_POST['position_name'] ?? '';
	$positionName = is_string($postedPositionName) ? trim($postedPositionName) : '';

	if (!is_string($csrfToken) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
		$error = 'Your session expired. Reload the page and try again.';
	} elseif ($action === 'create_position') {
		if ($positionName === '' || strlen($positionName) > 100) {
			$error = 'Enter a position name up to 100 characters long.';
		} else {
			try {
				$conn->begin_transaction();
				$selectedElection = lockElectionRosterForEditing($conn, $electionId);
				if (!$selectedElection) {
					$conn->rollback();
					$error = 'This election roster is locked after the first ballot is submitted or voting ends.';
				} else {
					$positionInsert = $conn->prepare('INSERT INTO positions (election_id, position_name) VALUES (?, ?)');
					$positionInsert->bind_param('is', $electionId, $positionName);
					$positionInsert->execute();
					$positionInsert->close();

					$adminId = (int) $_SESSION['account_id'];
					$description = "Added position {$positionName} to election {$selectedElection['election_name']}";
					$log = $conn->prepare("INSERT INTO activity_logs (admin_id, action, description) VALUES (?, 'Added position', ?)");
					$log->bind_param('is', $adminId, $description);
					$log->execute();
					$log->close();
					$conn->commit();
					$conn->close();
					header('Location: add-candidates.php?election_id=' . $electionId . '&position_added=1');
					exit;
				}
			} catch (mysqli_sql_exception $exception) {
				$conn->rollback();
				error_log($exception->getMessage());
				$error = $exception->getCode() === 1062
					? 'That position already exists for this election.'
					: 'The position could not be saved. Please try again.';
			}
		}
	} elseif ($action === 'remove_candidate') {
		try {
			$conn->begin_transaction();
			$selectedElection = lockElectionRosterForEditing($conn, $electionId);
			if (!$selectedElection) {
				$conn->rollback();
				$error = 'The roster cannot be changed after the first ballot is submitted or voting ends.';
			} else {
				$candidateCheck = $conn->prepare('SELECT fullname FROM candidates WHERE candidate_id = ? AND election_id = ? LIMIT 1 FOR UPDATE');
				$candidateCheck->bind_param('ii', $candidateId, $electionId);
				$candidateCheck->execute();
				$selectedCandidate = $candidateCheck->get_result()->fetch_assoc();
				$candidateCheck->close();

				if (!$selectedCandidate) {
					$conn->rollback();
					$error = 'That candidate could not be found in this election.';
				} else {
					$adminId = (int) $_SESSION['account_id'];
					$description = "Removed candidate {$selectedCandidate['fullname']} from election {$selectedElection['election_name']}";
					$log = $conn->prepare("INSERT INTO activity_logs (admin_id, action, description) VALUES (?, 'Removed candidate', ?)");
					$log->bind_param('is', $adminId, $description);
					$log->execute();
					$log->close();

					$remove = $conn->prepare('DELETE FROM candidates WHERE candidate_id = ? AND election_id = ?');
					$remove->bind_param('ii', $candidateId, $electionId);
					$remove->execute();
					$removed = $remove->affected_rows;
					$remove->close();
					if ($removed !== 1) {
						$conn->rollback();
						$error = 'That candidate could not be removed.';
					} else {
						$conn->commit();
						$conn->close();
						header('Location: add-candidates.php?election_id=' . $electionId . '&removed=1');
						exit;
					}
				}
			}
		} catch (mysqli_sql_exception $exception) {
			$conn->rollback();
			error_log($exception->getMessage());
			$error = 'The candidate could not be removed. Please try again.';
		}
	} elseif ($action !== 'add_candidate') {
		$error = 'Choose a valid candidate action.';
	} elseif ($voterAccountId < 1) {
		$error = 'Search for and select an active voter account to add as a candidate.';
	} else {
		try {
			$conn->begin_transaction();
			$selectedElection = lockElectionRosterForEditing($conn, $electionId);
			if (!$selectedElection) {
				$conn->rollback();
				$error = 'The roster cannot be changed after the first ballot is submitted or voting ends.';
			} else {
				$positionCheck = $conn->prepare('SELECT position_id FROM positions WHERE position_id = ? AND election_id = ? LIMIT 1');
				$positionCheck->bind_param('ii', $positionId, $electionId);
				$positionCheck->execute();
				$selectedPosition = $positionCheck->get_result()->fetch_assoc();
				$positionCheck->close();

				if (!$selectedPosition) {
					$conn->rollback();
					$error = 'Choose a position belonging to the selected election.';
				} else {
					$voterQuery = $conn->prepare("SELECT account_id, fullname, student_id, year_level, course FROM accounts WHERE account_id = ? AND role = 'voter' AND status = 'active' AND student_id IS NOT NULL AND student_id <> '' LIMIT 1 FOR UPDATE");
					$voterQuery->bind_param('i', $voterAccountId);
					$voterQuery->execute();
					$selectedVoter = $voterQuery->get_result()->fetch_assoc();
					$voterQuery->close();

					if (!$selectedVoter) {
						$conn->rollback();
						$error = 'That active voter account could not be found. Search again and select a voter.';
					} else {
						$duplicateQuery = $conn->prepare('SELECT candidate_id FROM candidates WHERE election_id = ? AND student_id = ? LIMIT 1 FOR UPDATE');
						$duplicateQuery->bind_param('is', $electionId, $selectedVoter['student_id']);
						$duplicateQuery->execute();
						$alreadyCandidate = $duplicateQuery->get_result()->fetch_assoc();
						$duplicateQuery->close();

						if ($alreadyCandidate) {
							$conn->rollback();
							$error = 'This voter is already registered as a candidate in the selected election.';
						} else {
							$fullname = $selectedVoter['fullname'];
							$studentId = $selectedVoter['student_id'];
							$yearLevel = $selectedVoter['year_level'] ?? '';
							$course = $selectedVoter['course'] ?? '';
							$stmt = $conn->prepare("INSERT INTO candidates (election_id, position_id, fullname, student_id, year_level, course, photo, status) VALUES (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULL, 'active')");
							$stmt->bind_param('iissss', $electionId, $positionId, $fullname, $studentId, $yearLevel, $course);
							$stmt->execute();
							$candidateId = $conn->insert_id;
							$stmt->close();

							$adminId = (int) $_SESSION['account_id'];
							$description = "Added candidate {$fullname} to election {$selectedElection['election_name']}";
							$log = $conn->prepare("INSERT INTO activity_logs (admin_id, action, description) VALUES (?, 'Added candidate', ?)");
							$log->bind_param('is', $adminId, $description);
							$log->execute();
							$log->close();
							$conn->commit();
							$conn->close();
							header('Location: add-candidates.php?election_id=' . $electionId . '&added=' . $candidateId);
							exit;
						}
					}
				}
			}
		} catch (mysqli_sql_exception $exception) {
			$conn->rollback();
			error_log($exception->getMessage());
			$error = 'The candidate could not be saved. Check the candidate details and selected position, then try again.';
		}
	}
	$selectedElectionId = $electionId;
}

$selectedVoter = null;
if ($voterAccountId > 0) {
	$selectedVoterQuery = $conn->prepare("SELECT fullname, student_id FROM accounts WHERE account_id = ? AND role = 'voter' AND status = 'active' AND student_id IS NOT NULL AND student_id <> '' LIMIT 1");
	$selectedVoterQuery->bind_param('i', $voterAccountId);
	$selectedVoterQuery->execute();
	$selectedVoter = $selectedVoterQuery->get_result()->fetch_assoc();
	$selectedVoterQuery->close();
}

$electionResult = $conn->query("
	SELECT e.election_id, e.election_name, e.status, e.start_date
	FROM elections e
	WHERE e.end_date > NOW()
		AND ((e.status = 'scheduled' AND e.start_date > NOW())
			OR (e.status = 'ongoing' AND e.start_date <= NOW()))
		AND NOT EXISTS (SELECT 1 FROM votes v WHERE v.election_id = e.election_id)
	ORDER BY (e.status = 'ongoing') DESC, e.start_date ASC
");
$elections = $electionResult->fetch_all(MYSQLI_ASSOC);
$sidebarElectionResult = $conn->query("SELECT election_id, election_name FROM elections WHERE status = 'ongoing' AND start_date <= NOW() AND end_date > NOW() ORDER BY start_date DESC LIMIT 1");
$sidebarOngoingElection = $sidebarElectionResult->fetch_assoc() ?: null;
if (!$elections) {
	$selectedElectionId = 0;
} elseif (!in_array($selectedElectionId, array_map('intval', array_column($elections, 'election_id')), true)) {
	$selectedElectionId = (int) $elections[0]['election_id'];
}
$selectedElectionIndex = array_search($selectedElectionId, array_map('intval', array_column($elections, 'election_id')), true);
$selectedElectionStatus = $selectedElectionIndex === false ? '' : $elections[$selectedElectionIndex]['status'];

$positions = [];
$candidates = [];
if ($selectedElectionId > 0) {
	$positionQuery = $conn->prepare('SELECT position_id, position_name FROM positions WHERE election_id = ? ORDER BY position_order, position_name');
	$positionQuery->bind_param('i', $selectedElectionId);
	$positionQuery->execute();
	$positions = $positionQuery->get_result()->fetch_all(MYSQLI_ASSOC);
	$positionQuery->close();

	$candidateQuery = $conn->prepare('SELECT c.candidate_id, c.fullname, c.student_id, c.year_level, c.course, c.photo, c.status, p.position_name FROM candidates c INNER JOIN positions p ON p.position_id = c.position_id WHERE c.election_id = ? ORDER BY p.position_order, p.position_name, c.fullname');
	$candidateQuery->bind_param('i', $selectedElectionId);
	$candidateQuery->execute();
	$candidates = $candidateQuery->get_result()->fetch_all(MYSQLI_ASSOC);
	$candidateQuery->close();
}

$conn->close();

$adminName = $_SESSION['account_name'] ?? 'Administrator';
$adminInitial = strtoupper(substr($adminName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="theme-color" content="#f4fbfa">
	<title>Add Candidates | CCS E-Voting System</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
	<link rel="stylesheet" href="../front_end/style.css?v=9">
	<script src="../front_end/script.js?v=7" defer></script>
</head>
<body class="admin-page">
	<div class="admin-layout">
		<aside class="admin-sidebar">
			<a class="admin-brand" href="admin-landing-page.php"><img src="../image/logo-css.jpg" alt=""><span><strong>CCS E-Voting</strong><small>ADMINISTRATION</small></span></a>
			<p class="admin-nav-label">WORKSPACE</p>
			<nav class="admin-nav" aria-label="Admin navigation">
				<a class="admin-nav-link" href="admin-landing-page.php"><i class="ti ti-layout-dashboard" aria-hidden="true"></i> Dashboard</a>
				<a class="admin-nav-link" href="election-status.php"><i class="ti ti-calendar-event" aria-hidden="true"></i> Election overview</a>
				<a class="admin-nav-link admin-nav-current" href="add-candidates.php"><i class="ti ti-user-plus" aria-hidden="true"></i> Add candidates</a>
				<a class="admin-nav-link" href="manage-voters.php"><i class="ti ti-users" aria-hidden="true"></i> Manage voters</a>
			</nav>
			<?php if ($sidebarOngoingElection): ?>
				<div class="admin-sidebar-election">
					<span>ONGOING ELECTION</span>
					<a href="add-candidates.php?election_id=<?= (int) $sidebarOngoingElection['election_id'] ?>"><?= escape($sidebarOngoingElection['election_name']) ?></a>
				</div>
			<?php endif; ?>
		</aside>
		<div class="admin-main">
			<header class="admin-topbar">
				<div><span class="admin-topbar-kicker">COLLEGE OF COMPUTER STUDIES</span><strong>Candidate management</strong></div>
				<div class="admin-user"><span class="admin-avatar"><?= escape($adminInitial) ?></span><span class="admin-user-name"><?= escape($adminName) ?><small>Administrator</small></span><a class="admin-logout" href="../landing-page/index.php?logout=1" aria-label="Log out" title="Log out"><i class="ti ti-logout-2" aria-hidden="true"></i></a></div>
			</header>
			<main class="admin-content">
				<section class="admin-welcome"><div><p class="admin-eyebrow">CANDIDATE MANAGEMENT</p><h1>Add candidates</h1><p>Set up the roster before any voter submits a ballot.</p></div></section>
				<?php if ($error !== ''): ?><p class="admin-feedback admin-feedback-error" role="alert"><?= escape($error) ?></p><?php endif; ?>
				<?php if (isset($_GET['added'])): ?><p class="admin-feedback admin-feedback-success" role="status">Candidate added successfully.</p><?php endif; ?>
				<?php if (isset($_GET['removed'])): ?><p class="admin-feedback admin-feedback-success" role="status">Candidate removed. Any vote selections for that candidate were removed from the totals.</p><?php endif; ?>
				<?php if (isset($_GET['position_added'])): ?><p class="admin-feedback admin-feedback-success" role="status">Position added successfully.</p><?php endif; ?>
				<section class="admin-work-panel">
					<div class="admin-work-heading"><div><p class="admin-eyebrow">ELECTION SETUP</p><h2>Positions and candidates</h2></div><i class="ti ti-user-plus" aria-hidden="true"></i></div>
					<?php if (!$elections): ?>
						<div class="admin-empty-election"><span><i class="ti ti-calendar-off" aria-hidden="true"></i></span><div><strong>No elections available for roster setup</strong><p>Choose an upcoming election, or finish the roster for an ongoing election before its first ballot is submitted. Elections with ballots are locked to protect vote integrity.</p><a class="admin-inline-link" href="election-status.php">Go to election overview <i class="ti ti-arrow-right" aria-hidden="true"></i></a></div></div>
					<?php else: ?>
						<form class="admin-filter-form" method="get" action="add-candidates.php">
							<label for="election-filter">Election</label>
							<select id="election-filter" name="election_id" onchange="this.form.submit()">
								<?php foreach ($elections as $election): ?>
									<option value="<?= (int) $election['election_id'] ?>" <?= $selectedElectionId === (int) $election['election_id'] ? 'selected' : '' ?>><?= escape($election['election_name']) ?> (<?= escape(ucfirst($election['status'])) ?>)</option>
								<?php endforeach; ?>
							</select>
						</form>
						<form class="admin-form-grid" method="post" action="add-candidates.php">
							<input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
							<input type="hidden" name="action" value="create_position">
							<input type="hidden" name="election_id" value="<?= $selectedElectionId ?>">
							<div class="admin-form-field admin-form-field-wide"><label for="position_name">Add a position <span>*</span></label><input id="position_name" name="position_name" type="text" maxlength="100" placeholder="e.g. President" required></div>
							<button class="admin-primary-button admin-form-field-wide" type="submit"><i class="ti ti-list-plus" aria-hidden="true"></i> Add position</button>
						</form>
						<?php if (!$positions): ?>
							<p class="admin-list-empty">Add a position above (for example, President) before you can register candidates.</p>
						<?php else: ?>
							<p class="admin-list-empty"><?= $selectedElectionStatus === 'ongoing' ? 'Voting has started. You can finish setting up this roster because no ballots have been submitted yet; roster changes lock after the first ballot.' : 'Register a candidate below. Roster changes remain available during an ongoing election only until its first ballot is submitted.' ?> Fields marked with * are required.</p>
							<form class="admin-form-grid candidate-account-form" method="post" action="add-candidates.php">
								<input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
								<input type="hidden" name="action" value="add_candidate">
								<input type="hidden" name="election_id" value="<?= $selectedElectionId ?>">
								<div class="admin-form-field admin-form-field-wide"><label for="candidate-voter-search">Search voter by name or student ID <span>*</span></label><div class="admin-voter-search" data-voter-search><input id="candidate-voter-search" type="search" autocomplete="off" placeholder="Type a name or student ID" aria-autocomplete="list" aria-controls="candidate-voter-results" aria-expanded="false" value="<?= $selectedVoter ? escape($selectedVoter['fullname'] . ' — ' . $selectedVoter['student_id']) : '' ?>" required><input type="hidden" name="voter_account_id" value="<?= $selectedVoter ? $voterAccountId : '' ?>"><div class="admin-voter-search-results" id="candidate-voter-results" role="listbox" aria-label="Matching voter accounts" hidden></div><p class="admin-voter-search-status" data-search-status role="status" aria-live="polite"><?= $selectedVoter ? 'Selected voter account' : 'Only active voter accounts can be added as candidates.' ?></p></div></div>
								<div class="admin-form-field"><label for="position_id">Position <span>*</span></label><select id="position_id" name="position_id" required><option value="">Select a position</option><?php foreach ($positions as $position): ?><option value="<?= (int) $position['position_id'] ?>" <?= $positionId === (int) $position['position_id'] ? 'selected' : '' ?>><?= escape($position['position_name']) ?></option><?php endforeach; ?></select></div>
								<button class="admin-primary-button admin-form-field-wide" type="submit"><i class="ti ti-user-plus" aria-hidden="true"></i> Add candidate</button>
							</form>
						<?php endif; ?>
					<?php endif; ?>
				</section>

				<section class="admin-work-panel admin-list-panel">
					<div class="admin-work-heading"><div><p class="admin-eyebrow">CURRENT ELECTION</p><h2>Registered candidates</h2></div><span class="admin-count"><?= count($candidates) ?> total</span></div>
					<?php if ($candidates): ?>
						<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Candidate</th><th>Student ID</th><th>Year level</th><th>Course</th><th>Position</th><th>Status</th><th>Action</th></tr></thead><tbody>
						<?php foreach ($candidates as $candidate): ?><tr><td><?= escape($candidate['fullname']) ?></td><td><?= escape($candidate['student_id'] ?? '') ?></td><td><?= escape($candidate['year_level'] ?? '') ?></td><td><?= escape($candidate['course'] ?? '') ?></td><td><?= escape($candidate['position_name']) ?></td><td><span class="admin-table-status"><?= escape(ucfirst($candidate['status'])) ?></span></td><td><form method="post" action="add-candidates.php" data-confirm-title="Remove candidate?" data-confirm-message="The candidate will be removed from the election roster." data-confirm-action="Remove candidate"><input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="remove_candidate"><input type="hidden" name="election_id" value="<?= $selectedElectionId ?>"><input type="hidden" name="candidate_id" value="<?= (int) $candidate['candidate_id'] ?>"><button class="admin-small-button admin-small-button-danger" type="submit">Remove</button></form></td></tr><?php endforeach; ?>
						</tbody></table></div>
					<?php else: ?><p class="admin-list-empty">No candidates have been added to this election yet.</p><?php endif; ?>
				</section>
			</main>
		</div>
	</div>
</body>
</html>
