<?php
// =============================================================
//  student/summary.php — Ballot Summary (post-vote review)
//  Shown after the student clicks "Submit Ballot" in vote.php.
//  Displays the student's actual choices pulled from the DB,
//  then lets them go back to edit or confirm to finalize.
// =============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireStudent();

$db   = getDB();
$user = currentUser();

// Guard: must be an ongoing election
$election = $db->query("SELECT * FROM elections WHERE status='ongoing' LIMIT 1")->fetch();
if (!$election) {
    setFlash('info', 'There is no active election at this time.');
    header('Location: /student/dashboard.php');
    exit;
}

// If already fully voted, just show the read-only receipt
$alreadyFinalized = !empty($user['has_voted']);

// ── AJAX: finalize (same logic as vote.php but sourced from here) ─
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        echo json_encode(['status'=>'error','message'=>'Security token mismatch.']);
        exit;
    }

    if ($action === 'finalize') {
        if ($alreadyFinalized) {
            echo json_encode(['status'=>'success','redirect'=>'/student/dashboard.php']);
            exit;
        }

        // Fetch student course for rep filtering
        $courseRow = $db->prepare("SELECT course FROM users WHERE id=?");
        $courseRow->execute([$user['id']]);
        $studentCourse = trim((string)($courseRow->fetchColumn() ?: ''));

        $allPosStmt = $db->prepare("SELECT id, title FROM positions WHERE election_id=?");
        $allPosStmt->execute([$election['id']]);
        $allPosList = $allPosStmt->fetchAll();

        $filteredTotal = 0;
        foreach ($allPosList as $p) {
            if (_canVotePos($p['title'], $studentCourse)) $filteredTotal++;
        }

        $votedStmt = $db->prepare("SELECT COUNT(*) FROM votes WHERE voter_id=? AND election_id=?");
        $votedStmt->execute([$user['id'], $election['id']]);
        $votedN = (int)$votedStmt->fetchColumn();

        $abstained = min(intval($_POST['abstained_count'] ?? 0), $filteredTotal - $votedN);

        if (($votedN + $abstained) < $filteredTotal) {
            echo json_encode(['status'=>'incomplete','message'=>'Please vote or abstain for all positions before submitting.']);
            exit;
        }

        $db->prepare("UPDATE users SET has_voted=1 WHERE id=?")->execute([$user['id']]);
        $_SESSION['has_voted'] = 1;
        setFlash('success', '🎉 Your votes have been finalized! Thank you for participating.');
        echo json_encode(['status'=>'success','redirect'=>'/student/dashboard.php']);
        exit;
    }

    echo json_encode(['status'=>'error','message'=>'Unknown action.']);
    exit;
}

// Helper: rep-position filter (mirrors vote.php)
function _isRepPos(string $title): bool {
    return (bool) preg_match('/\bRepresentative\b/i', $title);
}
function _canVotePos(string $title, string $course): bool {
    if (!_isRepPos($title)) return true;
    if ($course === '') return false;
    return stripos($title, $course) !== false;
}

// ── Load student course ──────────────────────────────────────
$courseRow = $db->prepare("SELECT course FROM users WHERE id=?");
$courseRow->execute([$user['id']]);
$studentCourse = trim((string)($courseRow->fetchColumn() ?: ''));

// ── Load all positions eligible for this student ─────────────
$posStmt = $db->prepare(
    "SELECT p.id, p.title, COUNT(c.id) AS cand_count
     FROM positions p
     LEFT JOIN candidates c ON c.position_id = p.id
     WHERE p.election_id=? GROUP BY p.id ORDER BY p.sort_order"
);
$posStmt->execute([$election['id']]);
$positions = array_values(array_filter(
    $posStmt->fetchAll(),
    fn($p) => _canVotePos($p['title'], $studentCourse)
));
$totalPositions = count($positions);

// ── Load what the student already voted ─────────────────────
$vpStmt = $db->prepare(
    "SELECT v.position_id, v.candidate_id,
            c.name AS cand_name, c.student_id AS cand_sid,
            c.photo, c.motto, c.platforms, c.achievements, c.partylist
     FROM votes v
     JOIN candidates c ON c.id = v.candidate_id
     WHERE v.voter_id=? AND v.election_id=?"
);
$vpStmt->execute([$user['id'], $election['id']]);
$votedRows = $vpStmt->fetchAll();

// Map positionId → vote data
$votedMap = [];
foreach ($votedRows as $vr) {
    $votedMap[$vr['position_id']] = $vr;
}

$votedCount = count($votedMap);

// Client-side abstain map will be passed back from JS (stored in sessionStorage in vote.php)
// We read voted positions from DB; abstained ones are tracked JS-side.

function photoSrc(?string $path): string {
    if (!$path) return '';
    return '/' . ltrim($path, '/');
}
function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    return strtoupper(($parts[0][0] ?? '') . ($parts[1][0] ?? ''));
}

$navActive = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ballot Summary | iVOTE CS</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&family=Geist:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/shared.css">
<style>
:root {
    --dark-green:   #12341d;
    --forest-green: #33553e;
    --muted-green:  #6d9078;
    --light-sage:   #a4c1ad;
    --page-bg:      #edf4f0;
    --gold:         #c8a84b;
    --gold-hover:   #e8c96b;
    --white:        #ffffff;
    --text-dark:    #0e1f14;
}
* { box-sizing:border-box; margin:0; padding:0; }

body {
    font-family:'Geist',sans-serif;
    background:var(--page-bg);
    color:var(--text-dark);
    min-height:100vh;
    padding:100px 20px 60px;
    display:flex;
    justify-content:center;
    align-items:flex-start;
}

.summary-wrapper {
    width:100%;
    max-width:860px;
    display:flex;
    flex-direction:column;
    gap:0;
}

/* ── Header banner ── */
.summary-header {
    background:var(--dark-green);
    color:#fff;
    padding:32px 36px;
    border-radius:24px 24px 0 0;
    border-bottom:4px solid var(--gold);
    text-align:center;
}
.summary-header h1 {
    font-family:'Montserrat',sans-serif;
    font-weight:800; font-size:1.85rem; margin:0; letter-spacing:-0.5px;
}
.summary-header p {
    opacity:0.75; margin-top:8px; font-weight:300; font-size:0.9rem;
}
.summary-header .badge {
    display:inline-block; margin-bottom:14px;
    background:rgba(200,168,75,0.2); border:1px solid var(--gold);
    border-radius:100px; padding:4px 14px;
    font-family:'Montserrat',sans-serif; font-size:9px; font-weight:800;
    letter-spacing:2px; text-transform:uppercase; color:var(--gold);
}

/* ── Progress indicator ── */
.progress-bar-wrap {
    background:var(--dark-green);
    padding:0 36px 24px;
}
.progress-info {
    display:flex; justify-content:space-between; align-items:center;
    font-size:12px; color:rgba(255,255,255,0.6); margin-bottom:8px;
    font-family:'Montserrat',sans-serif; font-weight:700;
}
.progress-track {
    background:rgba(255,255,255,0.12); border-radius:100px; height:6px; overflow:hidden;
}
.progress-fill {
    height:100%; border-radius:100px;
    background:linear-gradient(to right, var(--gold), var(--gold-hover));
    transition:width 0.5s ease;
}

/* ── Ballot list ── */
.ballot-list {
    background:#f9fbfa;
    padding:28px 28px;
    display:flex;
    flex-direction:column;
    gap:18px;
    overflow-y:auto;
}

/* ── Voted candidate card ── */
.detailed-card {
    background:var(--white);
    border-radius:18px;
    border:1px solid #e2ece5;
    box-shadow:0 4px 16px rgba(18,52,29,0.06);
    padding:22px;
    display:flex;
    gap:22px;
    transition:border-color 0.2s;
}
.detailed-card:hover { border-color:var(--gold); }

.card-identity {
    flex-shrink:0; width:190px; text-align:center;
    border-right:2px dashed #e2ece5; padding-right:22px;
    display:flex; flex-direction:column; align-items:center;
}
.portrait-circle {
    width:90px; height:90px; border-radius:50%;
    border:3px solid var(--gold); object-fit:cover;
    background:var(--dark-green); color:var(--gold);
    display:flex; align-items:center; justify-content:center;
    font-family:'Montserrat',sans-serif; font-size:24px; font-weight:800;
    margin-bottom:12px; overflow:hidden;
}
.portrait-circle img { width:100%; height:100%; object-fit:cover; }
.position-label {
    font-family:'Montserrat',sans-serif; text-transform:uppercase;
    font-size:0.7rem; color:var(--forest-green); font-weight:700;
    margin-bottom:4px; letter-spacing:1px;
}
.candidate-name {
    font-family:'Montserrat',sans-serif; margin:0;
    font-size:1.05rem; font-weight:800; color:var(--dark-green); line-height:1.2;
}
.candidate-id { font-size:0.78rem; color:var(--muted-green); margin-top:4px; }
.voted-badge {
    margin-top:10px; display:inline-flex; align-items:center; gap:5px;
    background:#d1fae5; color:#065f46; border-radius:100px;
    font-family:'Montserrat',sans-serif; font-size:9px; font-weight:800;
    padding:4px 10px; letter-spacing:1px; text-transform:uppercase;
}

.card-details { flex-grow:1; }
.motto-text { font-style:italic; color:var(--gold); font-weight:600; margin-bottom:12px; font-size:0.9rem; }
.section-title {
    font-family:'Montserrat',sans-serif; font-size:0.75rem; font-weight:700;
    color:var(--forest-green); text-transform:uppercase; letter-spacing:1px;
    margin:12px 0 5px;
}
.bullet-list { list-style:none; padding:0; margin:0 0 12px; }
.bullet-list li {
    position:relative; padding-left:14px; font-size:0.82rem;
    color:#4b5563; margin-bottom:4px; line-height:1.4;
}
.bullet-list li::before { content:'•'; position:absolute; left:0; color:var(--gold); font-weight:bold; }

/* ── Abstain card ── */
.abstain-summary-card {
    background:#fff5f5; border:2px dashed #b91c1c; border-radius:16px;
    padding:22px 24px; display:flex; align-items:center; gap:18px;
}
.abstain-icon-s {
    width:52px; height:52px; border-radius:50%; background:#fee2e2;
    border:2px solid #b91c1c; display:flex; align-items:center;
    justify-content:center; flex-shrink:0;
}
.abstain-icon-s svg { width:26px; fill:#b91c1c; }
.abstain-title-s {
    font-family:'Montserrat',sans-serif; color:#b91c1c;
    font-size:1rem; font-weight:800; margin:0 0 4px;
}
.abstain-desc-s { color:#7f1d1d; font-size:0.85rem; margin:0; }

/* ── Pending (not voted/abstained) card ── */
.pending-card {
    background:#fffbeb; border:2px dashed #d97706; border-radius:16px;
    padding:22px 24px; display:flex; align-items:center; gap:18px;
}
.pending-icon {
    width:52px; height:52px; border-radius:50%; background:#fef3c7;
    border:2px solid #d97706; display:flex; align-items:center;
    justify-content:center; flex-shrink:0; font-size:22px;
}
.pending-title {
    font-family:'Montserrat',sans-serif; color:#92400e;
    font-size:1rem; font-weight:800; margin:0 0 4px;
}
.pending-desc { color:#78350f; font-size:0.85rem; margin:0; }

/* ── Actions bar ── */
.summary-actions {
    background:var(--white);
    padding:22px 36px;
    display:flex; justify-content:space-between; align-items:center;
    border-top:1px solid #e2ece5;
    border-radius:0 0 24px 24px;
    box-shadow:0 10px 30px rgba(18,52,29,0.08);
}
.btn {
    font-family:'Montserrat',sans-serif; font-weight:700;
    padding:14px 28px; border-radius:10px; cursor:pointer;
    text-decoration:none; transition:0.2s; font-size:0.88rem;
    border:none; text-align:center;
}
.btn-cancel {
    background:transparent; color:var(--forest-green);
    border:2px solid var(--forest-green);
}
.btn-cancel:hover { background:#e2ece5; }
.btn-confirm {
    background:var(--gold); color:var(--dark-green);
    box-shadow:0 4px 15px rgba(200,168,75,0.3);
}
.btn-confirm:hover { background:var(--gold-hover); transform:translateY(-2px); }
.btn-confirm:disabled { opacity:0.5; cursor:not-allowed; transform:none; }

/* ── Already voted receipt mode ── */
.receipt-notice {
    background:rgba(16,185,129,0.12); border:1px solid rgba(16,185,129,0.3);
    border-radius:14px; padding:14px 20px; margin-bottom:20px;
    color:#065f46; font-size:13px; font-weight:600; text-align:center;
}

@media(max-width:700px){
    .detailed-card { flex-direction:column; }
    .card-identity { width:100%; border-right:none; border-bottom:2px dashed #e2ece5; padding-right:0; padding-bottom:16px; }
    .summary-actions { flex-direction:column; gap:12px; }
    .btn { width:100%; }
    body { padding:90px 12px 40px; }
}
</style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="summary-wrapper">

    <!-- Header -->
    <div class="summary-header">
        <div class="badge">🗳️ iVOTE CS — <?= htmlspecialchars($election['title']) ?></div>
        <h1>Review Your Ballot</h1>
        <p>
            <?php if ($alreadyFinalized): ?>
                Your ballot has been finalized. Below is your voting receipt.
            <?php else: ?>
                Double-check your selections below. You can go back to make changes or confirm to finalize.
            <?php endif; ?>
        </p>
    </div>

    <!-- Progress bar (voted / total) -->
    <div class="progress-bar-wrap">
        <div class="progress-info">
            <span>Positions voted</span>
            <span id="prog-label"><?= $votedCount ?> / <?= $totalPositions ?></span>
        </div>
        <div class="progress-track">
            <div class="progress-fill" id="prog-fill"
                 style="width:<?= $totalPositions > 0 ? round($votedCount / $totalPositions * 100) : 0 ?>%"></div>
        </div>
    </div>

    <!-- Ballot list -->
    <div class="ballot-list" id="ballotOutput">

        <?php if ($alreadyFinalized): ?>
        <div class="receipt-notice">✅ Your vote was recorded on <?= htmlspecialchars($election['title']) ?>. Thank you for participating!</div>
        <?php endif; ?>

        <?php foreach ($positions as $pos):
            $posId = $pos['id'];
            if (isset($votedMap[$posId])):
                $v     = $votedMap[$posId];
                $photo = photoSrc($v['photo'] ?? '');
                $platforms    = array_filter(array_map('trim', explode("\n", $v['platforms']    ?? '')));
                $achievements = array_filter(array_map('trim', explode("\n", $v['achievements'] ?? '')));
        ?>
        <!-- Voted candidate card -->
        <div class="detailed-card">
            <div class="card-identity">
                <div class="position-label"><?= htmlspecialchars($pos['title']) ?></div>
                <div class="portrait-circle">
                    <?php if ($photo): ?>
                        <img src="<?= htmlspecialchars($photo) ?>"
                             alt="<?= htmlspecialchars($v['cand_name']) ?>"
                             onerror="this.parentNode.innerHTML='<span><?= htmlspecialchars(initials($v['cand_name'])) ?></span>'">
                    <?php else: ?>
                        <span><?= htmlspecialchars(initials($v['cand_name'])) ?></span>
                    <?php endif; ?>
                </div>
                <h2 class="candidate-name"><?= htmlspecialchars($v['cand_name']) ?></h2>
                <div class="candidate-id">ID: <?= htmlspecialchars($v['cand_sid'] ?? '') ?></div>
                <div class="voted-badge">✓ Voted</div>
            </div>
            <div class="card-details">
                <?php if ($v['motto']): ?>
                    <div class="motto-text"><?= htmlspecialchars($v['motto']) ?></div>
                <?php endif; ?>
                <?php if ($v['partylist']): ?>
                    <div class="section-title">Partylist</div>
                    <p style="font-size:0.85rem;color:#4b5563;margin-bottom:10px"><?= htmlspecialchars($v['partylist']) ?></p>
                <?php endif; ?>
                <?php if ($platforms): ?>
                    <div class="section-title">Platforms &amp; Advocacies</div>
                    <ul class="bullet-list">
                        <?php foreach ($platforms as $pl): ?>
                            <li><?= htmlspecialchars($pl) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($achievements): ?>
                    <div class="section-title">Achievements &amp; Honors</div>
                    <ul class="bullet-list">
                        <?php foreach ($achievements as $ac): ?>
                            <li><?= htmlspecialchars($ac) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <?php else: ?>
        <!-- Abstained / pending card — rendered from JS abstain data, default shows pending -->
        <div class="pending-card" id="pos-card-<?= $posId ?>" data-pos-id="<?= $posId ?>" data-pos-title="<?= htmlspecialchars($pos['title']) ?>">
            <div class="pending-icon">⏳</div>
            <div>
                <p class="pending-title"><?= htmlspecialchars($pos['title']) ?></p>
                <p class="pending-desc">No vote recorded yet. Go back to vote or abstain.</p>
            </div>
        </div>
        <?php endif; ?>

        <?php endforeach; ?>
    </div>

    <!-- Actions -->
    <div class="summary-actions">
        <?php if ($alreadyFinalized): ?>
            <span></span>
            <a href="/student/dashboard.php" class="btn btn-confirm">Go to Dashboard</a>
        <?php else: ?>
            <button class="btn btn-cancel" onclick="goBack()">← Go Back &amp; Edit</button>
            <button class="btn btn-confirm" id="confirmBtn" onclick="confirmVotes()">Confirm &amp; Submit ✓</button>
        <?php endif; ?>
    </div>
</div>

<script>
const CSRF_TOKEN      = <?= json_encode(csrfToken()) ?>;
const TOTAL_POSITIONS = <?= (int)$totalPositions ?>;
const VOTED_COUNT_DB  = <?= (int)$votedCount ?>;

// Retrieve abstain map saved by vote.php into sessionStorage
let abstainMap = {};
try {
    const saved = sessionStorage.getItem('abstainMap');
    if (saved) abstainMap = JSON.parse(saved);
} catch(e) {}

// Render abstained cards for positions not in DB votes
document.querySelectorAll('[data-pos-id]').forEach(card => {
    const posId    = card.dataset.posId;
    const posTitle = card.dataset.posTitle;
    if (abstainMap[posId]) {
        card.className = 'abstain-summary-card';
        card.innerHTML = `
            <div class="abstain-icon-s">
                <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm5 11H7v-2h10v2z"/></svg>
            </div>
            <div>
                <p class="abstain-title-s">${posTitle}</p>
                <p class="abstain-desc-s">You officially abstained from voting for this position.</p>
            </div>`;
    }
});

// Update progress with abstains
(function updateProgress() {
    const abstainCount = Object.keys(abstainMap).length;
    const total = VOTED_COUNT_DB + abstainCount;
    const pct = TOTAL_POSITIONS > 0 ? Math.min(100, Math.round(total / TOTAL_POSITIONS * 100)) : 0;
    document.getElementById('prog-label').textContent = total + ' / ' + TOTAL_POSITIONS;
    document.getElementById('prog-fill').style.width = pct + '%';
})();

function goBack() {
    window.location.href = '/student/vote.php';
}

async function confirmVotes() {
    const abstainCount = Object.keys(abstainMap).length;
    const handled = VOTED_COUNT_DB + abstainCount;
    if (handled < TOTAL_POSITIONS) {
        alert('You have not voted or abstained for all positions. Please go back and complete your ballot.');
        return;
    }

    const btn = document.getElementById('confirmBtn');
    btn.disabled = true;
    btn.textContent = 'Submitting…';

    const fd = new FormData();
    fd.append('action', 'finalize');
    fd.append('abstained_count', abstainCount);
    fd.append('csrf_token', CSRF_TOKEN);

    try {
        const res  = await fetch(window.location.href, { method:'POST', body:fd });
        const data = await res.json();
        if (data.status === 'success') {
            sessionStorage.removeItem('abstainMap');
            window.location.href = data.redirect || '/student/dashboard.php';
        } else if (data.status === 'incomplete') {
            alert(data.message);
            btn.disabled = false;
            btn.textContent = 'Confirm & Submit ✓';
        } else {
            alert(data.message || 'An error occurred. Please try again.');
            btn.disabled = false;
            btn.textContent = 'Confirm & Submit ✓';
        }
    } catch(e) {
        alert('Network error. Please try again.');
        btn.disabled = false;
        btn.textContent = 'Confirm & Submit ✓';
    }
}
</script>
</body>
</html>