<?php
// =============================================================
//  admin/elections.php  (replaces electionmanager.php — elections side)
// =============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = getDB();

// ---- HANDLE POST ACTIONS -----------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Create new election
    if ($action === 'create') {
        $title  = trim($_POST['title'] ?? '');
        $desc   = trim($_POST['description'] ?? '');
        $start  = $_POST['start_date'] ?? '';
        $end    = $_POST['end_date']   ?? '';

        if ($title && $start && $end && $end > $start) {
            $now    = date('Y-m-d H:i:s');
            $status = $start <= $now ? ($end >= $now ? 'ongoing' : 'ended') : 'upcoming';
            $stmt   = $db->prepare(
                "INSERT INTO elections (title, description, start_date, end_date, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$title, $desc, $start, $end, $status, $_SESSION['user_id']]);
            $elecId = $db->lastInsertId();

            // Add default positions for this election
            $positions = [
                'President','Vice-President Internal','Vice-President External',
                'General Secretary','Deputy Secretary','Treasurer','Auditor',
                'Business Manager','Public Information Officer',
                'Bachelor of Science in Biology Representative','Bachelor of Science in Computer Science Representative',
                'Bachelor of Science in Human Services Representative','Bachelor of Science in Psychology Representative',
                'Bachelor of Science in Mathematics Representative'
            ];
            $posStmt = $db->prepare("INSERT INTO positions (election_id, title, sort_order) VALUES (?, ?, ?)");
            foreach ($positions as $i => $pos) {
                $posStmt->execute([$elecId, $pos, $i]);
            }

            setFlash('success', 'Election created with ' . count($positions) . ' positions.');
        } else {
            setFlash('error', 'Please fill all fields and ensure end date is after start date.');
        }
        header('Location: /admin/elections.php'); exit;
    }

   // Update status manually
    if ($action === 'setstatus') {
        $id     = intval($_POST['election_id']);
        $status = $_POST['status'] ?? '';
        
        if ($id && in_array($status, ['ongoing', 'ended'])) {
            // Check if another election is already ongoing
            if ($status === 'ongoing') {
                $stmtCheck = $db->prepare("SELECT COUNT(*) FROM elections WHERE status='ongoing' AND id != ?");
                $stmtCheck->execute([$id]);
                if ($stmtCheck->fetchColumn() > 0) {
                    setFlash('error', 'Cannot start this election. Another election is currently ongoing.');
                    header('Location: /admin/elections.php'); exit;
                }
            }
            
            $db->prepare("UPDATE elections SET status=? WHERE id=?")->execute([$status, $id]);
            setFlash('success', 'Election status updated.');
        }
        header('Location: /admin/elections.php'); exit;
    }

    // Reschedule election
    if ($action === 'reschedule') {
        $id    = intval($_POST['election_id']);
        $start = $_POST['start_date'] ?? '';
        $end   = $_POST['end_date']   ?? '';
        
        if ($id && $start && $end && $end > $start) {
            $now    = date('Y-m-d H:i:s');
            $newStatus = $start <= $now ? ($end >= $now ? 'ongoing' : 'ended') : 'upcoming';
            
            // Check for ongoing conflict if the reschedule would set it to ongoing
            if ($newStatus === 'ongoing') {
                $stmtCheck = $db->prepare("SELECT COUNT(*) FROM elections WHERE status='ongoing' AND id != ?");
                $stmtCheck->execute([$id]);
                if ($stmtCheck->fetchColumn() > 0) {
                    setFlash('error', 'Reschedule failed: The new dates would overlap with another ongoing election.');
                    header('Location: /admin/elections.php'); exit;
                }
            }

            $db->prepare(
                "UPDATE elections SET start_date=?, end_date=?, status=? WHERE id=?"
            )->execute([$start, $end, $newStatus, $id]);
            setFlash('success', 'Election rescheduled successfully.');
        } else {
            setFlash('error', 'Invalid dates. End date must be after start date.');
        }
        header('Location: /admin/elections.php'); exit;
    }

    // Delete election
    if ($action === 'delete') {
        $id = intval($_POST['election_id']);
        if ($id) {
            $db->prepare("DELETE FROM elections WHERE id=?")->execute([$id]);
            setFlash('success', 'Election deleted.');
        }
        header('Location: /admin/elections.php'); exit;
    }
}

// Auto-sync election statuses based on current time
$db->exec("UPDATE elections SET status='ongoing' WHERE start_date <= NOW() AND end_date >= NOW() AND status='upcoming'");
$db->exec("UPDATE elections SET status='ended'   WHERE end_date   <  NOW() AND status='ongoing'");

// Load all elections
$elections = $db->query(
    "SELECT e.*, u.first_name, u.last_name,
            (SELECT COUNT(*) FROM positions p WHERE p.election_id=e.id) AS pos_count,
            (SELECT COUNT(*) FROM candidates c WHERE c.election_id=e.id) AS cand_count,
            (SELECT COUNT(DISTINCT voter_id) FROM votes v WHERE v.election_id=e.id) AS vote_count
     FROM elections e
     LEFT JOIN users u ON u.id = e.created_by
     ORDER BY e.created_at DESC"
)->fetchAll();

$anyOngoing = in_array('ongoing', array_column($elections, 'status'));

$navActive     = 'dashboard';
$sidebarActive = 'elections';
$flash         = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Elections | iVOTE CS</title>
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600&family=Montserrat:wght@700;800;900&display=swap" rel="stylesheet">
    <base href="<?= BASE_URL ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/shared.css">
    <style>
        body { background:#f8fafc; padding-top:0px; }
        .page-wrap { display:flex; }
        .main { flex:1; margin-left:280px; padding:40px; }
        .page-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:28px; flex-wrap:wrap; gap:16px; }
        .page-header h2 { font-family:'Montserrat',sans-serif; color:#12341d; font-size:1.6rem; font-weight:900; margin:0; }

        /* Create form card */
        .create-card {
            background:#fff; border-radius:20px; padding:32px;
            border:1px solid #e2e8f0; margin-bottom:32px;
            box-shadow:0 4px 16px rgba(0,0,0,0.06);
        }
        .create-card h3 { font-family:'Montserrat',sans-serif; color:#12341d; font-size:1.1rem; font-weight:800; margin-bottom:20px;}
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
        .form-group { display:flex; flex-direction:column; gap:6px; }
        .form-group.full { grid-column:span 2; }
        .form-label { font-size:0.78rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; }
        .form-input {
            padding:11px 14px; border:1px solid #e2e8f0; border-radius:10px;
            font-size:14px; font-family:'Geist',sans-serif; outline:none; transition:border 0.2s;
        }
        .form-input:focus { border-color:#33553e; box-shadow:0 0 0 3px rgba(51,85,62,0.08); }
        .btn-create {
            padding:12px 28px; background:#12341d; color:#fff; border:none;
            border-radius:12px; font-family:'Montserrat',sans-serif; font-weight:800;
            font-size:14px; cursor:pointer; transition:all 0.2s; margin-top:8px;
        }
        .btn-create:hover { background:#33553e; transform:translateY(-2px); }

        /* Election cards */
        .elections-list { display:flex; flex-direction:column; gap:18px; }
        .election-card {
            background:#fff; border-radius:18px; padding:24px 28px;
            border:1px solid #e2e8f0; box-shadow:0 3px 12px rgba(0,0,0,0.05);
            transition:all 0.25s;
        }
        .election-card:hover { box-shadow:0 8px 24px rgba(18,52,29,0.1); }
        .election-top { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; }
        .election-title { font-family:'Montserrat',sans-serif; font-size:1.15rem; font-weight:800; color:#12341d; }
        .election-desc  { color:#64748b; font-size:0.88rem; margin-top:4px; line-height:1.5; }
        .election-dates { color:#33553e; font-size:0.82rem; margin-top:6px; font-weight:600; }

        .pill {
            display:inline-block; padding:5px 14px; border-radius:100px;
            font-family:'Montserrat',sans-serif; font-weight:700; font-size:0.72rem;
            letter-spacing:0.5px; text-transform:uppercase; flex-shrink:0;
        }
        .pill-ongoing  { background:#d1fae5; color:#065f46; }
        .pill-upcoming { background:#dbeafe; color:#1e40af; }
        .pill-ended    { background:#f1f5f9; color:#64748b;  }

        .election-meta { display:flex; gap:24px; margin-top:16px; flex-wrap:wrap; }
        .meta-item { display:flex; flex-direction:column; }
        .meta-label { font-size:0.72rem; color:#94a3b8; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; }
        .meta-value { font-family:'Montserrat',sans-serif; font-size:1.1rem; font-weight:800; color:#12341d; margin-top:3px; }

        .election-actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:18px; padding-top:18px; border-top:1px solid #f1f5f9; align-items:center; }
        .act-btn {
            padding:8px 18px; border-radius:10px; font-size:0.82rem;
            font-weight:700; font-family:'Montserrat',sans-serif; cursor:pointer; transition:all 0.2s; border:none;
        }
        .btn-ongoing  { background:#d1fae5; color:#065f46; }
        .btn-ended    { background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0; }
        .btn-reschedule { background:#fef9c3; color:#854d0e; border:1px solid #fde68a; }
        .btn-delete   { background:#fee2e2; color:#dc2626; }
        .act-btn:hover { filter:brightness(92%); transform:translateY(-1px); }

        /* Reschedule inline form */
        .reschedule-form {
            display:none; margin-top:16px; padding:20px; background:#fefce8;
            border:1px solid #fde68a; border-radius:14px;
        }
        .reschedule-form.open { display:block; }
        .reschedule-form .rform-title {
            font-family:'Montserrat',sans-serif; font-size:0.85rem; font-weight:800;
            color:#854d0e; margin-bottom:14px;
        }
        .reschedule-form .rform-grid { display:grid; grid-template-columns:1fr 1fr auto; gap:12px; align-items:end; }
        .reschedule-form .form-label { color:#92400e; }
        .reschedule-form .form-input { background:#fff; border-color:#fde68a; }
        .reschedule-form .form-input:focus { border-color:#f59e0b; box-shadow:0 0 0 3px rgba(245,158,11,0.12); }
        .btn-rsave {
            padding:11px 20px; background:#854d0e; color:#fff; border:none;
            border-radius:10px; font-family:'Montserrat',sans-serif; font-weight:800;
            font-size:0.82rem; cursor:pointer; transition:all 0.2s; white-space:nowrap;
        }
        .btn-rsave:hover { background:#6b3a08; transform:translateY(-1px); }

        .empty-state { text-align:center; padding:60px 20px; color:#94a3b8; }
        .empty-state .icon { font-size:3rem; margin-bottom:16px; }

        /* ── Mobile responsive ── */
        @media (max-width: 768px) {
            .main { margin-left:0; padding:20px; }
            .page-header h2 { font-size:1.25rem; margin-left: 45px; margin-top: 7px;}

            /* Create form: 2-col grid collapses to 1-col */
            .form-grid { grid-template-columns:1fr; }
            /* The .full span:2 rule is irrelevant at 1-col but reset it cleanly */
            .form-group.full { grid-column:span 1; }
            /* Create button full-width */
            .btn-create { width:100%; text-align:center; }

            /* Election card padding */
            .election-card { padding:18px 16px; }

            /* Reschedule form: 3-col (start, end, save) → stacked */
            .reschedule-form .rform-grid {
                grid-template-columns:1fr;
            }

            /* Election top: title and pill can wrap, already does — just tighten gap */
            .election-top { gap:10px; }
        }
    </style>
</head>
<body>
<?php // $navActive='dashboard'; require_once __DIR__ . '/../includes/navbar.php'; ?>
<div class="page-wrap">
    <?php require_once __DIR__ . '/../includes/admin_sidebar.php'; ?>
    <main class="main">
        <?php if ($flash): ?>
            <div class="flash <?= $flash['type'] ?>"><?= htmlspecialchars($flash['message']) ?></div>
        <?php endif; ?>

        <div class="page-header">
            <h2>Manage Elections</h2>
        </div>

        <!-- Create form -->
        <div class="create-card">
            <h3><img src = /assets/img/icons/add.png> Create New Election</h3>
            <form method="POST" action="/admin/elections.php">
                <input type="hidden" name="action" value="create">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Election Title</label>
                        <input class="form-input" type="text" name="title" placeholder="e.g. COS General Elections 2026" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <input class="form-input" type="text" name="description" placeholder="Short description (optional)">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Start Date &amp; Time</label>
                        <input class="form-input" type="datetime-local" name="start_date" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">End Date &amp; Time</label>
                        <input class="form-input" type="datetime-local" name="end_date" required>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn-create">Create Election</button>
                        <span style="font-size:0.78rem;color:#94a3b8;margin-top:6px">14 default positions will be added automatically.</span>
                    </div>
                </div>
            </form>
        </div>

        <!-- Elections list -->
        <?php if (empty($elections)): ?>
        <div class="empty-state">
            <div class="icon">📭</div>
            <p>No elections yet. Create one above to get started.</p>
        </div>
        <?php else: ?>
        <div class="elections-list">
        <?php foreach ($elections as $e): ?>
            <div class="election-card">
                <div class="election-top">
                    <div>
                        <div class="election-title"><?= htmlspecialchars($e['title']) ?></div>
                        <?php if ($e['description']): ?>
                            <div class="election-desc"><?= htmlspecialchars($e['description']) ?></div>
                        <?php endif; ?>
                        <div class="election-dates">
                            <?= date('M d, Y g:ia', strtotime($e['start_date'])) ?>
                            &nbsp;→&nbsp;
                            <?= date('M d, Y g:ia', strtotime($e['end_date'])) ?>
                        </div>
                    </div>
                    <span class="pill pill-<?= $e['status'] ?>"><?= ucfirst($e['status']) ?></span>
                </div>

                <div class="election-meta">
                    <div class="meta-item">
                        <span class="meta-label">Positions</span>
                        <span class="meta-value"><?= $e['pos_count'] ?></span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Candidates</span>
                        <span class="meta-value"><?= $e['cand_count'] ?></span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Votes Cast</span>
                        <span class="meta-value"><?= $e['vote_count'] ?></span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Created By</span>
                        <span class="meta-value" style="font-size:0.9rem"><?= htmlspecialchars($e['first_name'] . ' ' . $e['last_name']) ?></span>
                    </div>
                </div>

                <div class="election-actions">
                    <!-- Status overrides -->
                   <form method="POST" style="display:contents">
    <input type="hidden" name="action" value="setstatus">
    <input type="hidden" name="election_id" value="<?= $e['id'] ?>">
    
    <?php if ($e['status'] !== 'ongoing'): ?>
        <?php if ($anyOngoing): ?>
            <button type="button" class="act-btn" style="background:#f1f5f9; color:#94a3b8; cursor:not-allowed;" title="Another election is already ongoing" disabled>▶ Set Ongoing</button>
        <?php else: ?>
            <button type="submit" name="status" value="ongoing" class="act-btn btn-ongoing">▶ Set Ongoing</button>
        <?php endif; ?>
    <?php endif; ?>
    
    <?php if ($e['status'] !== 'ended'): ?>
        <button type="submit" name="status" value="ended" class="act-btn btn-ended">⏹ End Election</button>
    <?php endif; ?>
</form>

                    <!-- Reschedule toggle -->
                    <button type="button" class="act-btn btn-reschedule"
                            onclick="toggleReschedule(<?= $e['id'] ?>)">
                        Reschedule
                    </button>

                    <a href="/admin/candidates.php?election_id=<?= $e['id'] ?>" class="act-btn" style="background:#d5e8db;color:#12341d;text-decoration:none;padding:8px 18px;">Manage Candidates</a>

                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this election and ALL its data? This cannot be undone.')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="election_id" value="<?= $e['id'] ?>">
                        <button type="submit" class="act-btn btn-delete">🗑 Delete</button>
                    </form>
                </div>

                <!-- Reschedule inline form -->
                <div class="reschedule-form" id="reschedule-<?= $e['id'] ?>">
                    <div class="rform-title">Reschedule Election</div>
                    <form method="POST" action="/admin/elections.php">
                        <input type="hidden" name="action" value="reschedule">
                        <input type="hidden" name="election_id" value="<?= $e['id'] ?>">
                        <div class="rform-grid">
                            <div class="form-group">
                                <label class="form-label">New Start Date &amp; Time</label>
                                <input class="form-input" type="datetime-local" name="start_date"
                                       value="<?= date('Y-m-d\TH:i', strtotime($e['start_date'])) ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">New End Date &amp; Time</label>
                                <input class="form-input" type="datetime-local" name="end_date"
                                       value="<?= date('Y-m-d\TH:i', strtotime($e['end_date'])) ?>" required>
                            </div>
                            <button type="submit" class="btn-rsave">Save</button>
                        </div>
                    </form>
                </div>

            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>
</div>
<script src="<?= BASE_URL ?>assets/js/shared.js"></script>
<script>
    function toggleReschedule(id) {
        const panel = document.getElementById('reschedule-' + id);
        panel.classList.toggle('open');
    }
</script>
</body>
</html>