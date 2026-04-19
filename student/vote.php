<?php
// =============================================================
//  student/vote.php  — redesigned to match voting_of_candidates.html
//  Uses AJAX per-vote to match the single-position UX of the HTML design.
// =============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rate_limiter.php';

requireStudent();

$rateLimiter = new RateLimiter(getDB());

$db   = getDB();
$user = currentUser();

// Active election guard
$election = $db->query("SELECT * FROM elections WHERE status='ongoing' LIMIT 1")->fetch();
if (!$election) {
    setFlash('info', 'There is no active election at this time.');
    header('Location: /student/dashboard.php');
    exit;
}

// Already fully voted? Check the votes table for the CURRENT election
$chkVote = $db->prepare("SELECT COUNT(*) FROM votes WHERE election_id=? AND voter_id=?");
$chkVote->execute([$election['id'], $user['id']]);
if ((int)$chkVote->fetchColumn() > 0) {
    setFlash('info', 'You have already voted in this election. Thank you!');
    header('Location: /student/dashboard.php');
    exit;
}

// ── Program-representative filtering ────────────────────────
// Rep positions follow the pattern "<Program> Representative".
// A student may only vote the rep position whose title contains
// their own program/course. Executive positions are unaffected.
// currentUser() does not store 'course' in the session, so fetch it from the DB.
$courseRow = $db->prepare("SELECT course FROM users WHERE id=?");
$courseRow->execute([$user['id']]);
$studentCourse = trim((string)($courseRow->fetchColumn() ?: ''));

/**
 * Returns true when a position title is a program-representative seat.
 * Matches any title that ends with (or contains) "Representative"
 * and is NOT a purely executive role.
 */
function isRepPosition(string $title): bool {
    return (bool) preg_match('/\bRepresentative\b/i', $title);
}

/**
 * Returns true when the student is allowed to vote on this position.
 * Executive positions: always allowed.
 * Rep positions: only when the title contains the student's course name.
 */
function studentCanVotePosition(string $posTitle, string $studentCourse): bool {
    if (!isRepPosition($posTitle)) return true;          // executive — always
    if ($studentCourse === '')      return false;         // no course on record
    // e.g. "BS Psychology Representative" contains "BS Psychology"
    return stripos($posTitle, $studentCourse) !== false;
}
// ── AJAX handlers ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        echo json_encode(['status'=>'error','message'=>'Security token mismatch.']);
        exit;
    }

    // ✅ Rate limit only finalize — applied right after CSRF, before any DB work
    if ($action === 'finalize') {
        $rateLimiter->check('vote', (string)$user['id']);
    }

    // Cast a vote for one position
    if ($action === 'cast_vote') {
        $posId  = intval($_POST['position_id']  ?? 0);
        $candId = intval($_POST['candidate_id'] ?? 0);

        $chk = $db->prepare(
            "SELECT c.id, c.position_id
             FROM candidates c
             JOIN positions p ON p.id = c.position_id
             WHERE c.id=? AND c.position_id=? AND p.election_id=?"
        );
        $chk->execute([$candId, $posId, $election['id']]);
        $candRow = $chk->fetch();
        if (!$candRow) {
            echo json_encode(['status'=>'error','message'=>'Invalid candidate selection.']);
            exit;
        }

        $posTitle = $db->prepare("SELECT title FROM positions WHERE id=? AND election_id=?");
        $posTitle->execute([$posId, $election['id']]);
        $posTitleStr = (string)($posTitle->fetchColumn() ?: '');
        if (!studentCanVotePosition($posTitleStr, $studentCourse)) {
            echo json_encode(['status'=>'error','message'=>'You are not eligible to vote for this representative position.']);
            exit;
        }

        echo json_encode(['status'=>'success']);
        exit;
    }

    // Finalize ballot — persist all votes atomically, then mark has_voted.
    if ($action === 'finalize') {
        $allPosStmt = $db->prepare("SELECT id, title FROM positions WHERE election_id=?");
        $allPosStmt->execute([$election['id']]);
        $allPosList = $allPosStmt->fetchAll();
        $filteredTotal = 0;
        foreach ($allPosList as $p) {
            if (studentCanVotePosition($p['title'], $studentCourse)) $filteredTotal++;
        }

        $votedMapRaw = json_decode($_POST['voted_map'] ?? '{}', true);
        if (!is_array($votedMapRaw)) $votedMapRaw = [];

        $votedMapClean = [];
        foreach ($votedMapRaw as $posId => $candId) {
            if (empty($posId) || empty($candId)) continue;
            $votedMapClean[intval($posId)] = intval($candId);
        }

        $votedN    = count($votedMapClean);
        $abstained = min(intval($_POST['abstained_count'] ?? 0), $filteredTotal - $votedN);

        if (($votedN + $abstained) < $filteredTotal) {
            // ✅ Incomplete ballot — suspicious if repeated, record as failure
            $rateLimiter->recordFailure('vote', (string)$user['id']);
            echo json_encode(['status'=>'incomplete','message'=>'Please vote or abstain for all positions before submitting.']);
            exit;
        }

        foreach ($votedMapClean as $posId => $candId) {
            $chk = $db->prepare(
                "SELECT c.id
                 FROM candidates c
                 JOIN positions p ON p.id = c.position_id
                 WHERE c.id=? AND c.position_id=? AND p.election_id=?"
            );
            $chk->execute([$candId, $posId, $election['id']]);
            if (!$chk->fetch()) {
                // ✅ Tampered candidate — definitely record as failure
                $rateLimiter->recordFailure('vote', (string)$user['id']);
                echo json_encode(['status'=>'error','message'=>'Invalid candidate selection detected. Please reload and try again.']);
                exit;
            }
            $posTitleStmt = $db->prepare("SELECT title FROM positions WHERE id=? AND election_id=?");
            $posTitleStmt->execute([$posId, $election['id']]);
            $posTitleStr = (string)($posTitleStmt->fetchColumn() ?: '');
            if (!studentCanVotePosition($posTitleStr, $studentCourse)) {
                // ✅ Ineligible position — record as failure
                $rateLimiter->recordFailure('vote', (string)$user['id']);
                echo json_encode(['status'=>'error','message'=>'Ineligible position detected. Please reload and try again.']);
                exit;
            }
        }

        try {
            $db->beginTransaction();
            $insertVote = $db->prepare(
                "INSERT IGNORE INTO votes (election_id, position_id, candidate_id, voter_id) VALUES (?,?,?,?)"
            );
            foreach ($votedMapClean as $posId => $candId) {
                $insertVote->execute([$election['id'], $posId, $candId, $user['id']]);
            }
            $db->commit();
        } catch (\PDOException $e) {
            $db->rollBack();
            // ✅ DB error — record as failure so repeated errors get throttled
            $rateLimiter->recordFailure('vote', (string)$user['id']);
            echo json_encode(['status'=>'error','message'=>'A database error occurred. Please try again.']);
            exit;
        }

        // ✅ All good — reset the rate limit counter
        $rateLimiter->recordSuccess('vote', (string)$user['id']);
        $_SESSION['has_voted'] = 1;
        setFlash('success', '🎉 Your votes have been cast! Thank you for participating.');
        echo json_encode(['status'=>'success','redirect'=>'/student/summary.php']);
        exit;
    }

    echo json_encode(['status'=>'error','message'=>'Unknown action.']);
    exit;
}
// ── Load all positions + candidates ──────────────────────────
// Filter out rep positions that don't belong to the student's program.
$posStmt = $db->prepare(
    "SELECT p.*, COUNT(c.id) AS cand_count
     FROM positions p
     LEFT JOIN candidates c ON c.position_id = p.id
     WHERE p.election_id=? GROUP BY p.id ORDER BY p.sort_order"
);
$posStmt->execute([$election['id']]);
$allPositionsRaw = $posStmt->fetchAll();

// Keep only positions the student is eligible to vote on
$positions = array_values(array_filter(
    $allPositionsRaw,
    fn($p) => studentCanVotePosition($p['title'], $studentCourse)
));

// The number of positions the student must handle (vote or abstain on)
$totalPositions = count($positions);

// Positions AND candidates already voted on
$vpStmt = $db->prepare("SELECT position_id, candidate_id FROM votes WHERE voter_id=? AND election_id=?");
$vpStmt->execute([$user['id'], $election['id']]);
$votesData = $vpStmt->fetchAll();
$alreadyVotedIds = array_column($votesData, 'position_id');

$votedMapPHP = [];
foreach ($votesData as $v) {
    $votedMapPHP[$v['position_id']] = $v['candidate_id'];
}

// All candidates grouped by position
$candsByPos = [];
foreach ($positions as $pos) {
    $cStmt = $db->prepare(
        "SELECT id, student_id, name, course, partylist, motto, platforms, achievements, photo
         FROM candidates WHERE position_id=? ORDER BY id"
    );
    $cStmt->execute([$pos['id']]);
    $candsByPos[$pos['id']] = $cStmt->fetchAll();
}

function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    return strtoupper(($parts[0][0] ?? '') . ($parts[1][0] ?? ''));
}
function photoSrc(?string $path): string {
    if (!$path) return '';
    return '/' . ltrim($path, '/');
}

$navActive = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cast Your Vote | iVOTE CS</title>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/shared.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&family=Geist:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root{
  --dark-green:#12341d;--forest-green:#33553e;--muted-green:#6d9078;
  --light-sage:#a4c1ad;--very-light:#d5e8db;--page-bg:#edf4f0;
  --gold:#c8a84b;--gold-hover:#e8c96b;--white:#f9fbfa;--text-dark:#0e1f14;
  --text-light:#6d9078;--card-bg:#ffffff;
  --shadow:0 4px 20px rgba(18,52,29,0.10);--shadow-hover:0 8px 36px rgba(18,52,29,0.18);
  /* ── Single source of truth for fixed-bar heights ── */
  --navbar-h: 72px;
  --submitbar-h: 44px;
  --top-offset: calc(var(--navbar-h) + var(--submitbar-h)); /* 116px total */
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Geist','DM Sans',sans-serif;background:var(--dark-green);color:var(--text-dark);min-height:100vh;display:flex;flex-direction:column;}

/* ── Layout ── */
.layout{display:flex;margin-top:var(--top-offset);min-height:calc(100vh - var(--top-offset));}


/* ── Ballot submit btn in navbar area ── */
.ballot-submit-bar{
  position:fixed;top:var(--navbar-h);right:0;z-index:90;
  height:var(--submitbar-h);
  display:flex;align-items:center;gap:14px;
  padding:0 32px;
  background:var(--dark-green);
  border-bottom:1px solid rgba(200,168,75,0.25);
  width:100%;
  padding: 2%;
}
.election-title-bar{
  font-family:'Montserrat',sans-serif;font-size:11px;font-weight:700;
  color:var(--gold);letter-spacing:0.12em;text-transform:uppercase;flex:1;
}
.submit-btn{
  padding:10px 24px;background:var(--gold);color:var(--dark-green);border:none;
  border-radius:6px;font-family:'Montserrat',sans-serif;font-weight:700;font-size:12px;
  letter-spacing:0.08em;text-transform:uppercase;cursor:pointer;transition:background 0.2s;
}
.submit-btn:hover{background:var(--gold-hover);}

/* ── Sidebar ── */
aside{width:272px;min-width:272px;background:var(--forest-green);border-right:1px solid rgba(200,168,75,0.2);padding:28px 16px;position:fixed;top:var(--top-offset);bottom:0;overflow-y:auto;}
.sidebar-heading{font-family:'Montserrat',sans-serif;font-size:9px;letter-spacing:0.20em;text-transform:uppercase;color:var(--gold);font-weight:700;margin-bottom:16px;padding:0 8px;}
.sidebar-divider{font-family:'Montserrat',sans-serif;font-size:8px;letter-spacing:0.16em;text-transform:uppercase;color:var(--light-sage);font-weight:700;padding:16px 8px 8px;opacity:0.75;}
.nav-item{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:10px;cursor:pointer;margin-bottom:4px;border:1px solid transparent;transition:background 0.22s,border-color 0.22s,transform 0.15s;}
.nav-item:hover{background:rgba(200,168,75,0.08);border-color:rgba(200,168,75,0.2);transform:translateX(3px);}
.nav-item.active{background:rgba(200,168,75,0.14);border-color:var(--gold);transform:translateX(3px);}
.nav-item.active .nav-label{color:var(--gold);}
.nav-item.active .nav-icon{background:var(--gold);}
.nav-item.active .nav-icon svg{fill:var(--dark-green);}
.nav-item.voted-pos .nav-label::after{content:' ✓';color:#6ee7b7;font-weight:800;}
.nav-item.locked-pos{opacity:0.38;cursor:not-allowed;pointer-events:none;}
.nav-item.locked-pos .nav-label{color:var(--light-sage);}
.nav-item.locked-pos .nav-icon svg{fill:var(--muted-green);}
.nav-item.locked-pos .nav-label::after{content:' 🔒';font-size:0.75em;}
.nav-icon{width:32px;height:32px;border-radius:7px;background:var(--dark-green);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background 0.22s;}
.nav-icon svg{width:16px;height:16px;fill:var(--light-sage);transition:fill 0.22s;}
.nav-label{font-family:'Geist',sans-serif;font-size:13px;font-weight:500;color:var(--very-light);line-height:1.3;flex:1;}
.nav-count{font-family:'Montserrat',sans-serif;font-size:9px;font-weight:700;color:var(--text-light);background:var(--dark-green);padding:2px 7px;border-radius:20px;}

/* ── Main ── */
main{margin-left:272px;flex:1;padding:36px 40px;background:var(--page-bg);}
#page-content{animation:pageFadeIn 0.35s ease;}
@keyframes pageFadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

.page-header{margin-bottom:28px;padding-bottom:20px;border-bottom:1px solid rgba(18,52,29,0.1);}
.page-badge{display:inline-block;font-family:'Montserrat',sans-serif;font-size:9px;letter-spacing:0.18em;text-transform:uppercase;color:var(--forest-green);font-weight:700;background:var(--very-light);padding:4px 12px;border-radius:20px;margin-bottom:10px;}
.page-title{font-family:'Montserrat',sans-serif;font-size:28px;font-weight:800;color:var(--dark-green);margin-bottom:6px;letter-spacing:-0.01em;}
.page-desc{font-family:'Geist',sans-serif;font-size:14px;color:var(--text-light);}

/* ── Candidate cards ── */
.candidates-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:22px;}
.candidate-card{background:var(--card-bg);border-radius:16px;border:1px solid rgba(18,52,29,0.08);box-shadow:var(--shadow);overflow:hidden;transition:box-shadow 0.22s,transform 0.22s;}
.candidate-card:hover{box-shadow:var(--shadow-hover);transform:translateY(-3px);}
.card-header{background:linear-gradient(135deg,var(--dark-green),var(--forest-green));padding:22px 20px 18px;position:relative;}
.card-header-top{display:flex;align-items:flex-start;justify-content:space-between;}
.candidate-avatar{width:60px;height:60px;border-radius:50%;border:2.5px solid var(--gold);background:var(--forest-green);display:flex;align-items:center;justify-content:center;font-family:'Montserrat',sans-serif;font-size:18px;font-weight:800;color:var(--gold);flex-shrink:0;overflow:hidden;}
.candidate-avatar img{width:100%;height:100%;object-fit:cover;}
.dots-btn{background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.2);border-radius:8px;padding:6px 10px;cursor:pointer;display:flex;gap:4px;align-items:center;transition:background 0.18s;}
.dots-btn:hover{background:rgba(255,255,255,0.24);}
.dot{width:5px;height:5px;border-radius:50%;background:rgba(255,255,255,0.75);}
.candidate-number{position:absolute;top:12px;right:52px;font-family:'Montserrat',sans-serif;font-size:26px;font-weight:800;color:rgba(255,255,255,0.07);pointer-events:none;}
.candidate-name{font-family:'Montserrat',sans-serif;font-size:16px;font-weight:700;color:#fff;margin-top:12px;line-height:1.25;}
.candidate-motto{font-family:'Geist',sans-serif;font-size:11.5px;color:var(--gold);font-style:italic;margin-top:5px;line-height:1.45;opacity:0.92;}
.candidate-id{font-family:'Geist',sans-serif;font-size:11px;color:var(--light-sage);letter-spacing:0.05em;margin-top:5px;}

/* Expand panel */
.expand-panel{max-height:0;overflow:hidden;transition:max-height 0.38s cubic-bezier(0.4,0,0.2,1),opacity 0.3s ease;opacity:0;border-top:0px solid rgba(18,52,29,0.07);}
.expand-panel.open{max-height:900px;opacity:1;border-top-width:1px;}
.expand-inner{padding:6px 20px 18px;}
.expand-section{margin-top:14px;}
.expand-label{font-family:'Montserrat',sans-serif;font-size:9px;letter-spacing:0.18em;text-transform:uppercase;color:var(--text-light);font-weight:700;margin-bottom:5px;}
.expand-value{font-family:'Geist',sans-serif;font-size:13px;color:var(--text-dark);line-height:1.65;}
.platform-list,.achievement-list{list-style:none;padding:0;margin:0;}
.platform-list li,.achievement-list li{font-family:'Geist',sans-serif;font-size:12.5px;color:var(--text-dark);line-height:1.6;padding:5px 0 5px 18px;position:relative;border-bottom:1px solid rgba(18,52,29,0.05);}
.platform-list li:last-child,.achievement-list li:last-child{border-bottom:none;}
.platform-list li::before{content:'';position:absolute;left:0;top:12px;width:7px;height:7px;border-radius:50%;background:var(--gold);}
.achievement-list li::before{content:'★';position:absolute;left:0;top:5px;font-size:11px;color:var(--gold);}
.expand-divider{height:1px;background:rgba(18,52,29,0.07);margin:16px 0 0;}

/* Vote button */
.card-footer{padding:14px 20px 18px;}
.vote-btn-card{width:100%;padding:11px 0;background:var(--forest-green);color:#fff;border:none;border-radius:8px;font-family:'Montserrat',sans-serif;font-weight:700;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;cursor:pointer;transition:background 0.2s,transform 0.15s;}
.vote-btn-card:hover{background:var(--muted-green);transform:scale(1.01);}
.vote-btn-card.voted{background:var(--gold);color:var(--dark-green);pointer-events:none;}

/* Abstain card */
.abstain-card{background:var(--card-bg);border-radius:16px;border:1.5px dashed rgba(18,52,29,0.22);box-shadow:var(--shadow);overflow:hidden;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:40px 24px 32px;text-align:center;transition:box-shadow 0.22s,transform 0.22s,border-color 0.22s;}
.abstain-card:hover{box-shadow:var(--shadow-hover);transform:translateY(-3px);border-color:rgba(180,40,30,0.35);}
.abstain-card.is-abstained{border-style:solid;border-color:#b91c1c;background:#fff8f7;}
.abstain-icon-wrap{width:58px;height:58px;border-radius:50%;border:2.5px dashed rgba(18,52,29,0.22);background:var(--page-bg);display:flex;align-items:center;justify-content:center;margin-bottom:16px;transition:border-color 0.22s,background 0.22s;}
.abstain-card.is-abstained .abstain-icon-wrap{border-style:solid;border-color:#b91c1c;background:#fdecea;}
.abstain-icon-wrap svg{width:26px;height:26px;fill:var(--muted-green);transition:fill 0.22s;}
.abstain-card.is-abstained .abstain-icon-wrap svg{fill:#b91c1c;}
.abstain-heading{font-family:'Montserrat',sans-serif;font-size:15px;font-weight:800;color:var(--text-dark);margin-bottom:6px;}
.abstain-note{font-family:'Geist',sans-serif;font-size:12.5px;color:var(--text-light);line-height:1.65;margin-bottom:24px;}
.abstain-btn{width:100%;padding:11px 0;background:transparent;color:var(--muted-green);border:1.5px solid rgba(109,144,120,0.45);border-radius:8px;font-family:'Montserrat',sans-serif;font-weight:700;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;cursor:pointer;transition:background 0.2s,color 0.2s,border-color 0.2s,transform 0.15s;}
.abstain-btn:hover{background:rgba(109,144,120,0.08);border-color:var(--muted-green);color:var(--forest-green);}
.abstain-btn.is-active{background:#b91c1c;color:#fff;border-color:#b91c1c;pointer-events:none;}

/* ── Modals ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(10,28,16,0.72);backdrop-filter:blur(4px);z-index:999;align-items:center;justify-content:center;}
.modal-overlay.visible{display:flex;}
.modal-box{background:var(--white);border-radius:20px;padding:38px 36px 32px;width:420px;max-width:90vw;box-shadow:0 24px 64px rgba(10,28,16,0.38);border:1px solid rgba(200,168,75,0.25);animation:modalSlideIn 0.28s cubic-bezier(0.34,1.56,0.64,1);text-align:center;}
@keyframes modalSlideIn{from{opacity:0;transform:scale(0.88) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.modal-icon{width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,var(--dark-green),var(--forest-green));border:2.5px solid var(--gold);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;}
.modal-icon svg{width:30px;height:30px;fill:var(--gold);}
.modal-eyebrow{font-family:'Montserrat',sans-serif;font-size:9px;letter-spacing:0.22em;text-transform:uppercase;color:var(--muted-green);font-weight:700;margin-bottom:8px;}
.modal-title{font-family:'Montserrat',sans-serif;font-size:20px;font-weight:800;color:var(--dark-green);line-height:1.3;margin-bottom:8px;}
.modal-title span{color:var(--forest-green);}
.modal-sub{font-family:'Geist',sans-serif;font-size:13px;color:var(--text-light);line-height:1.6;margin-bottom:28px;}
.modal-actions{display:flex;gap:12px;}
.modal-cancel{flex:1;padding:12px;background:transparent;border:1.5px solid rgba(18,52,29,0.18);border-radius:9px;font-family:'Montserrat',sans-serif;font-weight:700;font-size:13px;color:var(--text-light);cursor:pointer;transition:border-color 0.2s,color 0.2s,background 0.2s;}
.modal-cancel:hover{border-color:var(--muted-green);color:var(--forest-green);background:rgba(18,52,29,0.04);}
.modal-confirm{flex:1;padding:12px;background:var(--forest-green);border:none;border-radius:9px;font-family:'Montserrat',sans-serif;font-weight:700;font-size:13px;color:#fff;cursor:pointer;transition:background 0.2s,transform 0.15s;}
.modal-confirm:hover{background:var(--dark-green);transform:scale(1.02);}

/* ══════════════════════════════════════════════════════════
   MOBILE RESPONSIVE — 768px and below
   ══════════════════════════════════════════════════════════ */

/* Dark overlay behind aside when open on mobile */
.aside-overlay{
  display:none;position:fixed;inset:0;
  background:rgba(0,0,0,0.55);
  z-index:109;backdrop-filter:blur(2px);
}
.aside-overlay.open{display:block;}

/* Sticky "Positions" pill button — mobile only, hidden on desktop */
.positions-pill-btn{
  display:none;
  position:sticky;
  top:0;
  z-index:80;
  width:100%;
  padding:10px 16px;
  background:var(--forest-green);
  color:var(--gold);
  border:none;
  border-top:1px solid rgba(200,168,75,0.25);
  border-bottom:1px solid rgba(200,168,75,0.25);
  font-family:'Montserrat',sans-serif;font-weight:700;font-size:12px;
  letter-spacing:0.10em;text-transform:uppercase;
  cursor:pointer;
  text-align:left;
  gap:10px;align-items:center;
  transition:background 0.2s;
}
.positions-pill-btn:hover{background:var(--dark-green);}
.positions-pill-btn .pill-icon{font-size:14px;}
.positions-pill-btn .pill-arrow{margin-left:auto;transition:transform 0.25s;}
.positions-pill-btn.open .pill-arrow{transform:rotate(180deg);}

@media(max-width:768px){

  /* Aside: slide off-screen, comes back when .open */
  aside{
    transform:translateX(-110%);
    transition:transform 0.3s cubic-bezier(0.4,0,0.2,1);
    z-index:110;
  }
  aside.open{transform:translateX(0);}

  /* Main: full width, reduced padding */
  main{margin-left:0;padding:0 0 24px;}

  /* Show the sticky pill button */
  .positions-pill-btn{display:flex;}

  /* Page content padding (sits below the sticky pill) */
  #page-content{padding:20px 16px 0;}

  /* ballot-submit-bar: hide 'Voting as' span, keep election title only */
  .voting-as-info{display:none;}
  .ballot-submit-bar{padding:0 14px;}
  .election-title-bar{font-size:10px;}
  .submit-btn{padding:9px 14px;font-size:11px;white-space:nowrap;}

  /* Page title smaller */
  .page-title{font-size:21px;}

  /* Candidate grid: single column */
  .candidates-grid{grid-template-columns:1fr;}

  /* Abstain card: tighter padding */
  .abstain-card{padding:28px 18px 24px;}

  /* Modal: tighter padding on small screens */
  .modal-box{padding:28px 20px 24px;}
}

  .ballot-submit-bar{
    margin-top: 5px; margin-bottom: 10px;
  }
</style>
</head>
<body>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<!-- Overlay: closes the position sidebar when tapped on mobile -->
<div class="aside-overlay" id="asideOverlay"></div>

<div class="ballot-submit-bar">
  <div class="election-title-bar">
    🗳️ Official Election &nbsp;·&nbsp; <?= htmlspecialchars($election['title']) ?>
    <span class="voting-as-info">&nbsp;&nbsp;|&nbsp;&nbsp;Voting as: <strong style="color:#fff"><?= htmlspecialchars($user['first_name'] . ' ' . $user['student_id']) ?></strong></span>
  </div>
  <button class="submit-btn" onclick="handleFinalSubmit()">Submit Ballot</button>
</div>

<div class="layout">

<aside id="voteAside">
  <div class="sidebar-heading">Candidate Positions</div>
  <?php
  $executivePositions = ['President','Vice-President Internal','Vice-President External','General Secretary','Deputy Secretary','Treasurer','Auditor','Business Manager','Public Information Officer'];
  $shownDivider = false;
  // Use full unfiltered list for sidebar display so students can see all positions,
  // but rep positions outside their program are rendered as locked (non-interactive).
  foreach ($allPositionsRaw as $i => $pos):
    $isRep     = isRepPosition($pos['title']);
    $canVote   = studentCanVotePosition($pos['title'], $studentCourse);
    if ($isRep && !$shownDivider):
      $shownDivider = true;
  ?>
    <div class="sidebar-divider">Program Representatives</div>
  <?php
    endif;
    // Only mark as "active" if it's actually in the votable positions list
    $isFirstVotable = (!$isRep || $canVote) && $pos['id'] === ($positions[0]['id'] ?? null);
    $alreadyVoted = in_array($pos['id'], $alreadyVotedIds);
    $lockedClass  = (!$canVote) ? 'locked-pos' : '';
  ?>
  <div class="nav-item <?= $isFirstVotable?'active':'' ?> <?= $alreadyVoted?'voted-pos':'' ?> <?= $lockedClass ?>"
       data-pos-id="<?= $pos['id'] ?>"
       <?= $canVote ? "onclick=\"navigateToPosition({$pos['id']}, this)\"" : '' ?>>
    <div class="nav-icon">
      <svg viewBox="0 0 24 24"><path d="<?= $isRep ? 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z' : ($i===0?'M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5L12 1z':'M16 11c1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 3-1.34 3-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5C15 14.17 10.33 13 8 13zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5C23 14.17 18.33 13 16 13z') ?>"/></svg>
    </div>
    <span class="nav-label"><?= htmlspecialchars($pos['title']) ?></span>
    <span class="nav-count"><?= $canVote ? $pos['cand_count'] : '—' ?></span>
  </div>
  <?php endforeach; ?>
</aside>

<main>
  <!-- Sticky "Positions" button — mobile only, opens the position sidebar -->
  <button class="positions-pill-btn" id="positionsPillBtn" aria-expanded="false" aria-label="Toggle positions sidebar">
    <span class="pill-icon">🗳️</span>
    Positions
    <span class="pill-arrow">▼</span>
  </button>
  <div id="page-content">
    <?php
    // Render the first position by default
    if (!empty($positions)):
      $firstPos = $positions[0];
      $firstCands = $candsByPos[$firstPos['id']] ?? [];
      $alreadyVotedFirst = in_array($firstPos['id'], $alreadyVotedIds);
      $votedCandFirst = $votedMapPHP[$firstPos['id']] ?? null;
    ?>
    <div class="page-header">
      <span class="page-badge">Voting — <?= htmlspecialchars($firstPos['title']) ?></span>
      <h1 class="page-title">Candidates for <?= htmlspecialchars($firstPos['title']) ?></h1>
      <p class="page-desc">Select one candidate and click "Vote this Candidate". You may view their platforms before deciding.</p>
    </div>
    <div class="candidates-grid">
      <?php foreach ($firstCands as $ci => $c):
        $photo = photoSrc($c['photo'] ?? '');
        $isVotedCand = ($votedCandFirst == $c['id']); // specifically check if this candidate is the one voted for
      ?>
      <div class="candidate-card" id="card-<?= $firstPos['id'] ?>-<?= $c['id'] ?>">
        <div class="card-header">
          <span class="candidate-number"><?= str_pad($ci+1,2,'0',STR_PAD_LEFT) ?></span>
          <div class="card-header-top">
            <div class="candidate-avatar">
              <?php if ($photo): ?>
                <img src="<?= htmlspecialchars($photo) ?>"
                     alt="<?= htmlspecialchars($c['name']) ?>"
                     onerror="this.parentNode.innerHTML='<span><?= htmlspecialchars(initials($c['name'])) ?></span>'">
              <?php else: ?>
                <span><?= htmlspecialchars(initials($c['name'])) ?></span>
              <?php endif; ?>
            </div>
            <button class="dots-btn" onclick="toggleExpand('<?= $firstPos['id'] ?>','<?= $c['id'] ?>')" title="View details">
              <span class="dot"></span><span class="dot"></span><span class="dot"></span>
            </button>
          </div>
          <div class="candidate-name"><?= htmlspecialchars($c['name']) ?></div>
          <?php if ($c['motto']): ?><div class="candidate-motto"><?= htmlspecialchars($c['motto']) ?></div><?php endif; ?>
          <div class="candidate-id">Student ID: <?= htmlspecialchars($c['student_id'] ?? '') ?></div>
        </div>

        <div class="expand-panel" id="expand-<?= $firstPos['id'] ?>-<?= $c['id'] ?>">
          <div class="expand-inner">
            <?php if ($c['partylist']): ?>
            <div class="expand-section">
              <div class="expand-label">Partylist</div>
              <div class="expand-value"><?= htmlspecialchars($c['partylist']) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($c['platforms']): ?>
            <div class="expand-divider"></div>
            <div class="expand-section">
              <div class="expand-label">Platforms &amp; Advocacies</div>
              <ul class="platform-list">
                <?php foreach (array_filter(array_map('trim', explode("\n", $c['platforms']))) as $pl): ?>
                  <li><?= htmlspecialchars($pl) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
            <?php endif; ?>
            <?php if ($c['achievements']): ?>
            <div class="expand-divider"></div>
            <div class="expand-section">
              <div class="expand-label">Achievements &amp; Honors</div>
              <ul class="achievement-list">
                <?php foreach (array_filter(array_map('trim', explode("\n", $c['achievements']))) as $ac): ?>
                  <li><?= htmlspecialchars($ac) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card-footer">
          <button class="vote-btn-card <?= $isVotedCand?'voted':'' ?>"
                  data-pos="<?= $firstPos['id'] ?>"
                  data-cand="<?= $c['id'] ?>"
                  data-cand-name="<?= htmlspecialchars($c['name']) ?>"
                  data-pos-title="<?= htmlspecialchars($firstPos['title']) ?>"
                  onclick="promptVote(this)">
            <?= $isVotedCand ? '✓ Voted' : 'Vote this Candidate' ?>
          </button>
        </div>
      </div>
      <?php endforeach; ?>

      <?php $isAbstained = false; /* abstains tracked in JS */ ?>
      <div class="abstain-card" id="abstain-<?= $firstPos['id'] ?>">
        <div class="abstain-icon-wrap">
          <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm5 11H7v-2h10v2z"/></svg>
        </div>
        <div class="abstain-heading">Abstain</div>
        <div class="abstain-note">Choose this if you prefer not to vote for any candidate for this position. Your abstention will be recorded.</div>
        <button class="abstain-btn"
                data-pos="<?= $firstPos['id'] ?>"
                data-pos-title="<?= htmlspecialchars($firstPos['title']) ?>"
                onclick="promptAbstain(this)">
          Abstain from this Position
        </button>
      </div>
    </div>
    <?php endif; ?>
  </div>
</main>
</div>

<div class="modal-overlay" id="voteModal">
  <div class="modal-box">
    <div class="modal-icon"><svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg></div>
    <div class="modal-eyebrow">Confirm Your Vote</div>
    <div class="modal-title">Vote <span id="vModal-name">Candidate</span>?</div>
    <div class="modal-sub">You are casting your vote for <strong id="vModal-name2">—</strong> for <strong id="vModal-pos">—</strong>. This action cannot be undone.</div>
    <div class="modal-actions">
      <button class="modal-cancel" onclick="closeModal('voteModal')">Cancel</button>
      <button class="modal-confirm" onclick="confirmVote()">Yes, Vote</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="abstainModal">
  <div class="modal-box">
    <div class="modal-icon" style="background:linear-gradient(135deg,#7f1d1d,#b91c1c);border-color:#fca5a5;">
      <svg viewBox="0 0 24 24" style="fill:#fecaca;"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm5 11H7v-2h10v2z"/></svg>
    </div>
    <div class="modal-eyebrow" style="color:#b91c1c;">Confirm Abstention</div>
    <div class="modal-title">Abstain from <span id="aModal-pos" style="color:#b91c1c;">this position</span>?</div>
    <div class="modal-sub">You are choosing <strong>not to vote</strong> for any candidate. Your abstention will be recorded.</div>
    <div class="modal-actions">
      <button class="modal-cancel" onclick="closeModal('abstainModal')">Cancel</button>
      <button class="modal-confirm" style="background:#b91c1c;" onclick="confirmAbstain()">Yes, Abstain</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="warnModal">
  <div class="modal-box" style="border-color:#b91c1c;">
    <div class="modal-icon" style="background:linear-gradient(135deg,#7f1d1d,#b91c1c);border-color:#fca5a5;">
      <svg viewBox="0 0 24 24" style="fill:#fecaca;"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
    </div>
    <div class="modal-eyebrow" style="color:#b91c1c;">Incomplete Ballot</div>
    <div class="modal-title" style="color:#b91c1c;">Action Required</div>
    <div class="modal-sub">You have not voted or abstained for all positions. Please complete your ballot before submitting.</div>
    <div class="modal-actions">
      <button class="modal-confirm" style="background:#b91c1c;width:100%;" onclick="closeModal('warnModal')">Return to Voting</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="submitModal">
  <div class="modal-box">
    <div class="modal-icon"><svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg></div>
    <div class="modal-eyebrow">Ready to Submit</div>
    <div class="modal-title">Finalize your ballot?</div>
    <div class="modal-sub">You have completed all positions. This is final and cannot be changed after submission.</div>
    <div class="modal-actions">
      <button class="modal-cancel" onclick="closeModal('submitModal')">Cancel</button>
      <button class="modal-confirm" onclick="finalizeBallot()">Confirm &amp; Submit</button>
    </div>
  </div>
</div>

<script>
const ELECTION_ID    = <?= (int)$election['id'] ?>;
const CSRF_TOKEN     = <?= json_encode(csrfToken()) ?>;
const TOTAL_POSITIONS = <?= (int)$totalPositions ?>;

// Track state
const votedMap    = <?= json_encode($votedMapPHP, JSON_FORCE_OBJECT) ?>;  // posId → candId
const abstainMap  = {};  // posId → true     (abstained)
const alreadyVoted = <?= json_encode($alreadyVotedIds) ?>;

let pendingVote    = null;  // {btn, posId, candId, candName, posTitle}
let pendingAbstain = null;  // {btn, posId, posTitle}

// ── Position navigation ────────────────────────────────────
const allPositions = <?= json_encode(array_map(fn($p) => [
    'id'         => (int)$p['id'],
    'title'      => $p['title'],
    'cand_count' => (int)$p['cand_count'],
], $positions)) ?>;

const candsByPos = <?= json_encode($candsByPos) ?>;

function navigateToPosition(posId, navEl) {
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    navEl.classList.add('active');

    const content = document.getElementById('page-content');
    content.style.opacity = '0';
    content.style.transform = 'translateY(8px)';
    content.style.transition = 'opacity 0.18s, transform 0.18s';
    setTimeout(() => {
        renderPosition(posId);
        content.style.transition = 'opacity 0.28s, transform 0.28s';
        content.style.opacity = '1';
        content.style.transform = 'translateY(0)';
    }, 180);
}

function getInitials(name) {
    return name.split(' ').map(p=>p[0]||'').slice(0,2).join('').toUpperCase();
}

function renderPosition(posId) {
    const pos   = allPositions.find(p => p.id === posId);
    const cands = candsByPos[posId] || [];
    const votedCandId = votedMap[posId];
    const isAbstained    = abstainMap[posId];

    let cardsHTML = '';
    cands.forEach((c, ci) => {
        const photoTag = c.photo
            ? `<img src="/${c.photo.replace(/^\//,'')}" alt="${c.name}" onerror="this.parentNode.innerHTML='<span>${getInitials(c.name)}</span>'">`
            : `<span>${getInitials(c.name)}</span>`;

        const platformItems = c.platforms
            ? c.platforms.split('\n').filter(x=>x.trim()).map(p=>`<li>${escHTML(p.trim())}</li>`).join('')
            : '';
        const achieveItems = c.achievements
            ? c.achievements.split('\n').filter(x=>x.trim()).map(a=>`<li>${escHTML(a.trim())}</li>`).join('')
            : '';

        const isVotedCand = (votedCandId == c.id);
        const btnClass = isVotedCand ? 'vote-btn-card voted' : 'vote-btn-card';
        const btnLabel = isVotedCand ? '✓ Voted' : 'Vote this Candidate';

        cardsHTML += `
        <div class="candidate-card" id="card-${posId}-${c.id}">
          <div class="card-header">
            <span class="candidate-number">${String(ci+1).padStart(2,'0')}</span>
            <div class="card-header-top">
              <div class="candidate-avatar">${photoTag}</div>
              <button class="dots-btn" onclick="toggleExpand('${posId}','${c.id}')" title="View details">
                <span class="dot"></span><span class="dot"></span><span class="dot"></span>
              </button>
            </div>
            <div class="candidate-name">${escHTML(c.name)}</div>
            ${c.motto ? `<div class="candidate-motto">${escHTML(c.motto)}</div>` : ''}
            <div class="candidate-id">Student ID: ${escHTML(c.student_id || '')}</div>
          </div>
          <div class="expand-panel" id="expand-${posId}-${c.id}">
            <div class="expand-inner">
              ${c.partylist ? `<div class="expand-section"><div class="expand-label">Partylist</div><div class="expand-value">${escHTML(c.partylist)}</div></div>` : ''}
              ${platformItems ? `<div class="expand-divider"></div><div class="expand-section"><div class="expand-label">Platforms &amp; Advocacies</div><ul class="platform-list">${platformItems}</ul></div>` : ''}
              ${achieveItems  ? `<div class="expand-divider"></div><div class="expand-section"><div class="expand-label">Achievements &amp; Honors</div><ul class="achievement-list">${achieveItems}</ul></div>` : ''}
            </div>
          </div>
          <div class="card-footer">
            <button class="${btnClass}"
                    data-pos="${posId}" data-cand="${c.id}"
                    data-cand-name="${escHTML(c.name)}"
                    data-pos-title="${escHTML(pos.title)}"
                    onclick="promptVote(this)">
              ${btnLabel}
            </button>
          </div>
        </div>`;
    });

    // Abstain card
    const aClass  = isAbstained ? 'abstain-card is-abstained' : 'abstain-card';
    const aBtnCls = isAbstained ? 'abstain-btn is-active'     : 'abstain-btn';
    const aBtnLbl = isAbstained ? '✓ Abstained'               : 'Abstain from this Position';
    cardsHTML += `
      <div class="${aClass}" id="abstain-${posId}">
        <div class="abstain-icon-wrap">
          <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm5 11H7v-2h10v2z"/></svg>
        </div>
        <div class="abstain-heading">Abstain</div>
        <div class="abstain-note">Choose this if you prefer not to vote for any candidate for this position. Your abstention will be recorded.</div>
        <button class="${aBtnCls}" data-pos="${posId}" data-pos-title="${escHTML(pos.title)}" onclick="promptAbstain(this)">
          ${aBtnLbl}
        </button>
      </div>`;

    document.getElementById('page-content').innerHTML = `
      <div class="page-header">
        <span class="page-badge">Voting — ${escHTML(pos.title)}</span>
        <h1 class="page-title">Candidates for ${escHTML(pos.title)}</h1>
        <p class="page-desc">Select one candidate and confirm your vote. You may expand each card to view their full profile.</p>
      </div>
      <div class="candidates-grid">${cardsHTML}</div>`;
}

function escHTML(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}

// ── Expand panel ───────────────────────────────────────────
function toggleExpand(posId, candId) {
    const panel = document.getElementById(`expand-${posId}-${candId}`);
    panel?.classList.toggle('open');
}

// ── Vote prompt ────────────────────────────────────────────
function promptVote(btn) {
    if (btn.classList.contains('voted')) return;
    pendingVote = {
        btn,
        posId:     btn.dataset.pos,
        candId:    btn.dataset.cand,
        candName:  btn.dataset.candName,
        posTitle:  btn.dataset.posTitle
    };
    document.getElementById('vModal-name').textContent  = pendingVote.candName;
    document.getElementById('vModal-name2').textContent = pendingVote.candName;
    document.getElementById('vModal-pos').textContent   = pendingVote.posTitle;
    document.getElementById('voteModal').classList.add('visible');
}

async function confirmVote() {
    if (!pendingVote) return;
    closeModal('voteModal');
    const {btn, posId, candId, candName, posTitle} = pendingVote;
    pendingVote = null;

    const fd = new FormData();
    fd.append('action',      'cast_vote');
    fd.append('position_id', posId);
    fd.append('candidate_id',candId);
    fd.append('election_id', ELECTION_ID);
    fd.append('csrf_token',  CSRF_TOKEN);

    try {
        const res  = await fetch(window.location.href, {method:'POST', body:fd});
        const data = await res.json();
        if (data.status === 'success') {
            votedMap[posId] = parseInt(candId);
            delete abstainMap[posId];
            // Update UI
            const card = document.querySelector(`#card-${posId}-${candId}`);
            if (card) {
                const allBtns = document.querySelectorAll(`.vote-btn-card[data-pos="${posId}"]`);
                allBtns.forEach(b => { b.classList.remove('voted'); b.textContent = 'Vote this Candidate'; });
                card.querySelector('.vote-btn-card').classList.add('voted');
                card.querySelector('.vote-btn-card').textContent = '✓ Voted';
            }
            // Clear abstain state
            const aCard = document.getElementById(`abstain-${posId}`);
            if (aCard) {
                aCard.classList.remove('is-abstained');
                const aBtn = aCard.querySelector('.abstain-btn');
                if (aBtn) { aBtn.classList.remove('is-active'); aBtn.textContent = 'Abstain from this Position'; }
            }
            // Mark sidebar
            markSidebarVoted(posId);
        } else {
            alert(data.message || 'Vote could not be recorded.');
        }
    } catch(e) { alert('Network error. Please try again.'); }
}

// ── Abstain prompt ─────────────────────────────────────────
function promptAbstain(btn) {
    if (btn.classList.contains('is-active')) return;
    pendingAbstain = {btn, posId: btn.dataset.pos, posTitle: btn.dataset.posTitle};
    document.getElementById('aModal-pos').textContent = pendingAbstain.posTitle;
    document.getElementById('abstainModal').classList.add('visible');
}

function confirmAbstain() {
    if (!pendingAbstain) return;
    closeModal('abstainModal');
    const {btn, posId} = pendingAbstain;
    pendingAbstain = null;

    abstainMap[posId] = true;
    delete votedMap[posId];
    // Reset vote buttons
    document.querySelectorAll(`.vote-btn-card[data-pos="${posId}"]`).forEach(b => {
        b.classList.remove('voted'); b.textContent = 'Vote this Candidate';
    });
    // Mark abstain card
    const aCard = document.getElementById(`abstain-${posId}`);
    if (aCard) {
        aCard.classList.add('is-abstained');
        const aBtn = aCard.querySelector('.abstain-btn');
        if (aBtn) { aBtn.classList.add('is-active'); aBtn.textContent = '✓ Abstained'; }
    }
    markSidebarVoted(posId);
}

function markSidebarVoted(posId) {
    const navEl = document.querySelector(`.nav-item[data-pos-id="${posId}"]`);
    if (navEl) navEl.classList.add('voted-pos');
}

// ── Submit ballot ──────────────────────────────────────────
function handleFinalSubmit() {
    const handledCount = Object.keys(votedMap).length + Object.keys(abstainMap).length;
    if (handledCount < TOTAL_POSITIONS) {
        document.getElementById('warnModal').classList.add('visible');
    } else {
        document.getElementById('submitModal').classList.add('visible');
    }
}

async function finalizeBallot() {
    closeModal('submitModal');

    // Persist abstain selections so summary.php can display them
    try { sessionStorage.setItem('abstainMap', JSON.stringify(abstainMap)); } catch(e) {}

    const fd = new FormData();
    fd.append('action',          'finalize');
    fd.append('abstained_count', Object.keys(abstainMap).length);
    fd.append('voted_map',       JSON.stringify(votedMap));
    fd.append('csrf_token',      CSRF_TOKEN);

    try {
        const res  = await fetch(window.location.href, {method:'POST', body:fd});
        const data = await res.json();
        if (data.status === 'success') {
            window._votingComplete = true;
            window.location.href = data.redirect || '/student/summary.php';
        } else {
            alert(data.message || 'Could not finalize ballot. Please try again.');
        }
    } catch(e) {
        alert('Network error while submitting. Please try again.');
    }
}

function closeModal(id) {
    document.getElementById(id)?.classList.remove('visible');
}

// ── Navigation guard ───────────────────────────────────────
// Warn the user if they try to leave mid-vote (navbar links, browser back, etc.)
window._votingComplete = false;

window.addEventListener('beforeunload', function(e) {
    if (window._votingComplete) return;
    const anySelection = Object.keys(votedMap).length + Object.keys(abstainMap).length;
    if (anySelection === 0) return;
    e.preventDefault();
    e.returnValue = '';
});

// Close modals on backdrop click
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('visible'); });
});

// ── Mobile: position sidebar (aside) toggle ────────────────
(function () {
    var aside   = document.getElementById('voteAside');
    var overlay = document.getElementById('asideOverlay');
    var pillBtn = document.getElementById('positionsPillBtn');

    function openAside() {
        aside.classList.add('open');
        overlay.classList.add('open');
        pillBtn.classList.add('open');
        pillBtn.setAttribute('aria-expanded', 'true');
    }
    function closeAside() {
        aside.classList.remove('open');
        overlay.classList.remove('open');
        pillBtn.classList.remove('open');
        pillBtn.setAttribute('aria-expanded', 'false');
    }

    pillBtn.addEventListener('click', function () {
        aside.classList.contains('open') ? closeAside() : openAside();
    });

    overlay.addEventListener('click', closeAside);

    // Auto-close when a position is selected (mobile only)
    aside.querySelectorAll('.nav-item').forEach(function (item) {
        item.addEventListener('click', function () {
            if (window.innerWidth <= 768) closeAside();
        });
    });
})();
</script>
</body>
</html>