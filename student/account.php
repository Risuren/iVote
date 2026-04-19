<?php
// =============================================================
//  student/account.php
// =============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireStudent();

$db   = getDB();
$user = currentUser();

// Load full user record
$stmt = $db->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

$error   = '';
$success = '';

// ---- HANDLE POST ACTIONS --------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // ── Update profile info ──────────────────────────────────
        if ($action === 'update_profile') {
            $fname   = trim($_POST['first_name']     ?? '');
            $lname   = trim($_POST['last_name']      ?? '');
            $mi      = trim($_POST['middle_initial'] ?? '');
            $year    = trim($_POST['year_level']     ?? '');

            $profilePicQuery = "";
            $params = [$fname, $lname, $mi, $year];

            if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
                $filename = 'student_' . $user['id'] . '_' . time() . '.' . $ext;
                $uploadDir = __DIR__ . '/../uploads/profiles/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $uploadDir . $filename)) {
                    $profilePicQuery = ", profile_pic=?";
                    $params[] = '/uploads/profiles/' . $filename;
                }
            }

            if (!$fname || !$lname) {
                $error = 'First and last name are required.';
            } else {
                $params[] = $user['id'];
                $upd = $db->prepare(
                    "UPDATE users SET first_name=?, last_name=?, middle_initial=?, year_level=? $profilePicQuery WHERE id=?"
                );
                $upd->execute($params);

                $_SESSION['first_name'] = $fname;
                $_SESSION['full_name']  = "$fname $lname";
                $success = 'Profile updated successfully.';

                $stmt->execute([$user['id']]);
                $profile = $stmt->fetch();
            }
        }

        // ── Change password ──────────────────────────────────────
        if ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $newPass = $_POST['new_password']     ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if (!$current || !$newPass || !$confirm) {
                $error = 'All password fields are required.';
            } elseif (strlen($newPass) < 8) {
                $error = 'New password must be at least 8 characters.';
            } elseif ($newPass !== $confirm) {
                $error = 'New passwords do not match.';
            } elseif (!password_verify($current, $profile['password_hash'])) {
                $error = 'Your current password is incorrect.';
            } else {
                $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
                $db->prepare("UPDATE users SET password_hash=? WHERE id=?")
                   ->execute([$hash, $user['id']]);
                $success = '✅ Password changed successfully.';
            }
        }
    }
}


$navActive = 'home';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account | iVOTE CS</title>
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600&family=Montserrat:wght@700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/shared.css">
    <style>
        * { box-sizing:border-box; margin:0; padding:0; }
        body {
            font-family:'Geist',sans-serif;
            background:linear-gradient(135deg,#33553e 0%,#b8d1c0 100%);
            background-attachment:fixed; min-height:100vh;
            display:flex; flex-direction:column; align-items:center;
            padding:100px 20px 60px;
        }
        h2,h3,.edit-btn,.btn-action,label { font-family:'Montserrat',sans-serif; }

        /* ── Main account card ── */
        .glass-card {
            background:rgba(255,255,255,0.45);
            backdrop-filter:blur(25px) saturate(180%);
            border:1px solid rgba(255,255,255,0.5); border-radius:30px;
            width:100%; max-width:900px; padding:40px;
            box-shadow:0 20px 50px rgba(0,0,0,0.1);
            margin-bottom:24px;
        }
        .card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:30px; }
        .title-area h2 { font-size:24px; color:#1d1d1f; font-weight:700; }
        .title-area p  { font-size:13px; color:#515154; margin-top:4px; }

        .status-pill {
            display:inline-block; padding:5px 14px; border-radius:100px;
            font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-top:6px;
        }
        .status-approved { background:#d1fae5; color:#065f46; }
        .status-pending  { background:#fef3c7; color:#92400e; }
        .status-rejected { background:#fee2e2; color:#991b1b; }

        .edit-btn {
            background:rgba(255,255,255,0.6); border:1px solid rgba(255,255,255,0.8);
            padding:8px 20px; border-radius:12px; font-size:14px; font-weight:600;
            color:#1d1d1f; cursor:pointer; transition:all 0.2s;
        }
        .edit-btn:hover { background:#fff; transform:translateY(-1px); }

        .main-grid { display:grid; grid-template-columns:240px 1fr; gap:40px; }
        @media(max-width:768px){ .main-grid{ grid-template-columns:1fr; } }

        /* Profile side */
        .profile-side { text-align:center; }
        .avatar-circle {
            width:150px; height:150px; background:rgba(255,255,255,0.5);
            border:2px solid white; border-radius:50%; margin:0 auto 20px;
            display:flex; align-items:center; justify-content:center;
            font-size:2.5rem; font-weight:900; color:#33553e;
            overflow:hidden;
        }
        .avatar-circle img { width:100%; height:100%; object-fit:cover; }
        .profile-name { font-family:'Montserrat',sans-serif; font-size:1.1rem; font-weight:700; color:#1d1d1f; }
        .profile-id   { font-size:0.85rem; color:#515154; margin-top:4px; }

        /* Info grid */
        .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        .input-group { display:flex; flex-direction:column; }
        .full-width { grid-column:1/-1; }

        .glass-input {
            background:rgba(255,255,255,0.3); border:1.5px solid rgba(255,255,255,0.5);
            border-radius:12px; padding:11px 14px; font-size:14px; color:#1d1d1f;
            font-family:'Geist',sans-serif; outline:none; transition:all 0.2s;
            width:100%;
        }
        .glass-input:focus { border-color:#33553e; background:rgba(255,255,255,0.5); }
        .glass-input[readonly], .glass-input:disabled { opacity:0.7; cursor:default; }
        select.glass-input { appearance:auto; }

        .hidden { display:none; }

        .btn-action {
            background:#33553e; color:white; border:none; padding:14px 28px;
            border-radius:14px; font-family:'Montserrat',sans-serif; font-size:14px;
            font-weight:800; cursor:pointer; transition:all 0.2s;
            box-shadow:0 6px 16px rgba(51,85,62,0.3); width:100%; margin-top:16px;
        }
        .btn-action:hover { background:#12341d; transform:translateY(-2px); }

        .alert { padding:12px 16px; border-radius:12px; margin-bottom:18px; font-size:13px; font-weight:600; }
        .alert-error   { background:rgba(239,68,68,0.15); color:#991b1b; border:1px solid rgba(239,68,68,0.3); }
        .alert-success { background:rgba(16,185,129,0.15); color:#065f46; border:1px solid rgba(16,185,129,0.3); }

        /* ── Password card ── */
        .password-card {
            width:100%; max-width:900px;
            background:rgba(255,255,255,0.45);
            backdrop-filter:blur(25px) saturate(180%);
            border:1px solid rgba(255,255,255,0.5); border-radius:30px;
            padding:36px 40px;
            box-shadow:0 20px 50px rgba(0,0,0,0.1);
        }
        .password-card h2 { font-size:20px; color:#1d1d1f; font-weight:700; margin-bottom:6px; }
        .password-card p  { font-size:13px; color:#515154; margin-bottom:24px; }
        .pw-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; }
        @media(max-width:768px){ .pw-grid{ grid-template-columns:1fr; } }
        .pw-label {
            font-family:'Montserrat',sans-serif; font-size:10px; font-weight:800;
            color:#515154; margin-bottom:6px; display:block; text-transform:uppercase;
        }
        .pw-input {
            background:rgba(255,255,255,0.3); border:1.5px solid rgba(255,255,255,0.5);
            border-radius:12px; padding:11px 14px; font-size:14px; color:#1d1d1f;
            font-family:'Geist',sans-serif; outline:none; transition:all 0.2s; width:100%;
        }
        .pw-input:focus { border-color:#33553e; background:rgba(255,255,255,0.5); }
        .pw-hint { font-size:11px; color:#515154; margin-top:4px; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<?php if ($error):   ?><div class="alert alert-error" style="width:100%;max-width:900px;margin-bottom:16px"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success" style="width:100%;max-width:900px;margin-bottom:16px"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<!-- ── Main profile card ─────────────────────────────────── -->
<div class="glass-card">
    <div class="card-header">
        <div class="title-area">
            <h2>My Account</h2>
            <p>Review and manage your registration details</p>
        </div>
        <button class="edit-btn" id="edit-button" onclick="toggleEdit()">Edit Profile</button>
    </div>

    <div class="main-grid">
        <div class="profile-side">
            <div class="avatar-circle">
                <?php if (!empty($profile['profile_pic'])): ?>
                    <img src="<?= htmlspecialchars($profile['profile_pic']) ?>" alt="Profile">
                <?php else: ?>
                    <?= htmlspecialchars(strtoupper(substr($profile['first_name'],0,1) . substr($profile['last_name'],0,1))) ?>
                <?php endif; ?>
            </div>
            <div class="profile-name"><?= htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']) ?></div>
            <div class="profile-id"><?= htmlspecialchars($profile['student_id']) ?></div>
           
            <?php if ($profile['has_voted']): ?>
                <div style="margin-top:12px;font-size:12px;color:#065f46;font-weight:700">Vote Cast</div>
            <?php endif; ?>
        </div>

        <div>
            <form method="POST" id="profileForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                <div class="info-grid">
                    <div class="input-group full-width hidden" id="picGroup">
                        <label>Change Profile Photo</label>
                        <input type="file" name="profile_pic" class="glass-input editable" accept="image/*">
                    </div>

                    <div class="input-group">
                        <label>First Name</label>
                        <input type="text" name="first_name" class="glass-input" value="<?= htmlspecialchars($profile['first_name']) ?>" readonly>
                    </div>
                    <div class="input-group">
                        <label>M.I.</label>
                        <input type="text" name="middle_initial" class="glass-input" value="<?= htmlspecialchars($profile['middle_initial'] ?? '') ?>" readonly>
                    </div>
                    <div class="input-group">
                        <label>Last Name</label>
                        <input type="text" name="last_name" class="glass-input" value="<?= htmlspecialchars($profile['last_name']) ?>" readonly>
                    </div>
                    <div class="input-group">
                        <label>Student No.</label>
                        <input type="text" class="glass-input" value="<?= htmlspecialchars($profile['student_id']) ?>" readonly>
                    </div>
                    <div class="input-group">
                        <label>Year Level</label>
                        <select name="year_level" class="glass-input" disabled>
                            <?php foreach (['1st Year','2nd Year','3rd Year','4th Year'] as $yr): ?>
                                <option <?= $profile['year_level']===$yr?'selected':'' ?>><?= $yr ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group">
                        <label>Course / Program</label>
                        <input type="text" class="glass-input" value="<?= htmlspecialchars($profile['course']) ?>" readonly>
                    </div>
                </div>
                <button type="submit" id="saveProfileBtn" class="btn-action hidden">Save Profile Changes</button>
            </form>
        </div>
    </div>
</div>

<!-- ── Change Password card ─────────────────────────────── -->
<div class="password-card">
    <h2>Change Password</h2>
    <p>Update your account password. Your new password must be at least 8 characters long.</p>

    <form method="POST">
        <input type="hidden" name="action" value="change_password">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

        <div class="pw-grid">
            <div>
                <label class="pw-label">Current Password</label>
                <input type="password" name="current_password" class="pw-input" placeholder="••••••••" required>
            </div>
            <div>
                <label class="pw-label">New Password</label>
                <input type="password" name="new_password" class="pw-input" placeholder="••••••••" required minlength="8">
                <div class="pw-hint">Minimum 8 characters</div>
            </div>
            <div>
                <label class="pw-label">Confirm New Password</label>
                <input type="password" name="confirm_password" class="pw-input" placeholder="••••••••" required>
            </div>
        </div>
        <button type="submit" class="btn-action" style="margin-top:20px">Update Password</button>
    </form>
</div>

<script src="<?= BASE_URL ?>assets/js/shared.js"></script>
<script>
function toggleEdit() {
    const btn      = document.getElementById('edit-button');
    const inputs   = document.querySelectorAll('.editable');
    const saveBtn  = document.getElementById('saveProfileBtn');
    const picGroup = document.getElementById('picGroup');
    const isEditing = btn.textContent === 'Cancel';

    if (isEditing) {
        inputs.forEach(input => {
            if (input.tagName === 'SELECT') input.disabled = true;
            else input.setAttribute('readonly', true);
        });
        btn.textContent = 'Edit Profile';
        saveBtn.classList.add('hidden');
        picGroup.classList.add('hidden');
    } else {
        inputs.forEach(input => {
            if (input.tagName === 'SELECT') input.disabled = false;
            else input.removeAttribute('readonly');
        });
        inputs[0].focus();
        btn.textContent = 'Cancel';
        saveBtn.classList.remove('hidden');
        picGroup.classList.remove('hidden');
    }
}
</script>
</body>
</html>