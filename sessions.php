
<?php

require __DIR__ . '/app.php';
require_admin();

/*
|--------------------------------------------------------------------------
| CLOSE ATTENDANCE SESSION
|--------------------------------------------------------------------------
| Mark students without attendance as absent.
| Revoke permission QR tokens.
*/

function close_attendance_session(PDO $pdo, int $sessionId): void
{
    if ($sessionId <= 0) {
        throw new RuntimeException('Invalid attendance session.');
    }

    $pdo->beginTransaction();

    try {

        $stmt = $pdo->prepare("
            SELECT id, status
            FROM attendance_sessions
            WHERE id = ?
            FOR UPDATE
        ");

        $stmt->execute([$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            throw new RuntimeException('Attendance session not found.');
        }

        if ($session['status'] !== 'open') {
            throw new RuntimeException('This attendance session is already closed.');
        }

        // Revoke permission QR tokens
        $stmt = $pdo->prepare("
            UPDATE permission_qr_tokens
            SET revoked_at = NOW()
            WHERE session_id = ?
              AND revoked_at IS NULL
        ");

        $stmt->execute([$sessionId]);

        // Mark students without attendance as absent
        $stmt = $pdo->prepare("
            INSERT INTO attendance
                (session_id, student_id, status, source)
            SELECT
                ?,
                u.id,
                'absent',
                'admin'
            FROM users u
            WHERE u.role = 'student'
              AND u.is_active = 1
              AND NOT EXISTS (
                  SELECT 1
                  FROM attendance a
                  WHERE a.session_id = ?
                    AND a.student_id = u.id
              )
        ");

        $stmt->execute([$sessionId, $sessionId]);

        // Close session
        $stmt = $pdo->prepare("
            UPDATE attendance_sessions
            SET status = 'closed'
            WHERE id = ?
        ");

        $stmt->execute([$sessionId]);

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}


/*
|--------------------------------------------------------------------------
| POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_check();

    $action = $_POST['action'] ?? '';


    /*
    |--------------------------------------------------------------------------
    | CREATE ATTENDANCE SESSION
    |--------------------------------------------------------------------------
    */

    if ($action === 'create') {

        $title = trim($_POST['title'] ?? '');
        $class = trim($_POST['class_name'] ?? '');
        $startInput = trim($_POST['starts_at'] ?? '');

        $start = strtotime($startInput);

        if (
            $title === '' ||
            $class === '' ||
            $start === false
        ) {

            flash(
                'Please enter a valid session title, class and start time.',
                'danger'
            );

            redirect('sessions.php');
        }

        try {

            $token = bin2hex(random_bytes(32));

            $startDate = date('Y-m-d H:i:s', $start);

            // Keep old database constraint satisfied.
            // These fields are not used for attendance status.
            $legacyLate = date('Y-m-d H:i:s', $start + 60);
            $legacyEnd = date('Y-m-d H:i:s', $start + 86400);

            $stmt = $pdo->prepare("
                INSERT INTO attendance_sessions
                (
                    title,
                    class_name,
                    starts_at,
                    late_after,
                    ends_at,
                    token_hash,
                    created_by,
                    status
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, 'open')
            ");

            $stmt->execute([
                $title,
                $class,
                $startDate,
                $legacyLate,
                $legacyEnd,
                hash('sha256', $token),
                user()['id']
            ]);

            $sessionId = (int) $pdo->lastInsertId();

            if ($sessionId <= 0) {
                throw new RuntimeException(
                    'Could not retrieve the new attendance session ID.'
                );
            }

            $_SESSION['new_qr_url'] =
                base_url() . '/scan.php?t=' . rawurlencode($token);

            $_SESSION['new_qr_title'] = $title;
            $_SESSION['new_qr_type'] = 'attendance';

            flash('Attendance QR session created successfully.', 'success');

            redirect('sessions.php?show=' . $sessionId);

        } catch (Throwable $e) {

            error_log(
                'Create attendance session error: ' . $e->getMessage()
            );

            flash(
                'Database Error: ' . $e->getMessage(),
                'danger'
            );

            redirect('sessions.php');
        }
    }


    /*
    |--------------------------------------------------------------------------
    | GENERATE PERMISSION QR
    |--------------------------------------------------------------------------
    */

    if ($action === 'permission_qr') {

        $sessionId = (int) ($_POST['session_id'] ?? 0);

        try {

            if ($sessionId <= 0) {
                throw new RuntimeException('Invalid session ID.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT id, title, status
                FROM attendance_sessions
                WHERE id = ?
                FOR UPDATE
            ");

            $stmt->execute([$sessionId]);

            $session = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$session || $session['status'] !== 'open') {
                throw new RuntimeException(
                    'Attendance session is not available.'
                );
            }

            // Revoke previous permission QR tokens
            $stmt = $pdo->prepare("
                UPDATE permission_qr_tokens
                SET revoked_at = NOW()
                WHERE session_id = ?
                  AND revoked_at IS NULL
            ");

            $stmt->execute([$sessionId]);

            $token = bin2hex(random_bytes(32));

            $expiresAt = '9999-12-31 23:59:59';

            $stmt = $pdo->prepare("
                INSERT INTO permission_qr_tokens
                (
                    session_id,
                    token_hash,
                    expires_at,
                    created_by
                )
                VALUES (?, ?, ?, ?)
            ");

            $stmt->execute([
                $sessionId,
                hash('sha256', $token),
                $expiresAt,
                user()['id']
            ]);

            $pdo->commit();

            $_SESSION['new_qr_url'] =
                base_url() . '/permission.php?t=' . rawurlencode($token);

            $_SESSION['new_qr_title'] = $session['title'];
            $_SESSION['new_qr_type'] = 'permission';

            flash('Permission QR generated successfully.', 'success');

            redirect('sessions.php?permission_show=' . $sessionId);

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Permission QR error: ' . $e->getMessage()
            );

            flash(
                'Permission QR Error: ' . $e->getMessage(),
                'danger'
            );

            redirect('sessions.php');
        }
    }


    /*
    |--------------------------------------------------------------------------
    | CLOSE SESSION
    |--------------------------------------------------------------------------
    */

    if ($action === 'close') {

        $sessionId = (int) ($_POST['session_id'] ?? 0);

        try {

            close_attendance_session($pdo, $sessionId);

            flash(
                'Session closed successfully. Students without attendance were marked absent.',
                'success'
            );

        } catch (Throwable $e) {

            error_log(
                'Close attendance session error: ' . $e->getMessage()
            );

            flash(
                'Close Session Error: ' . $e->getMessage(),
                'danger'
            );
        }

        redirect('sessions.php');
    }
}


/*
|--------------------------------------------------------------------------
| LOAD SESSION HISTORY
|--------------------------------------------------------------------------
*/

try {

    $stmt = $pdo->query("
        SELECT
            id,
            title,
            class_name,
            starts_at,
            status,
            created_at
        FROM attendance_sessions
        ORDER BY id DESC
        LIMIT 50
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    error_log('Load attendance sessions error: ' . $e->getMessage());

    exit(
        'Could not load attendance sessions. Database Error: ' .
        e($e->getMessage())
    );
}


/*
|--------------------------------------------------------------------------
| LOAD QR INFORMATION
|--------------------------------------------------------------------------
*/

$qrUrl = $_SESSION['new_qr_url'] ?? '';
$qrTitle = $_SESSION['new_qr_title'] ?? '';
$qrType = $_SESSION['new_qr_type'] ?? '';

$showId = (int) ($_GET['show'] ?? 0);
$permissionShowId = (int) ($_GET['permission_show'] ?? 0);

unset(
    $_SESSION['new_qr_url'],
    $_SESSION['new_qr_title'],
    $_SESSION['new_qr_type']
);


/*
|--------------------------------------------------------------------------
| PAGE
|--------------------------------------------------------------------------
*/

page_header('QR Sessions');

?>

<div class="welcome-row mb-4">

    <div>
        <h2>QR Attendance Sessions</h2>

        <p class="text-muted">
            Create attendance and permission QR codes for your class.
            Close attendance manually when the class is finished.
        </p>
    </div>

</div>


<!-- CREATE ATTENDANCE SESSION -->

<div class="panel mb-4">

    <div class="panel-head">
        <h5>
            <i class="bi bi-qr-code-scan me-2"></i>
            Create Attendance Session
        </h5>
    </div>

    <form method="POST" class="p-3">

        <input
            type="hidden"
            name="csrf"
            value="<?= e(csrf_token()) ?>"
        >

        <input
            type="hidden"
            name="action"
            value="create"
        >

        <div class="row g-3">

            <div class="col-md-5">

                <label class="form-label">
                    Session Title
                </label>

                <input
                    type="text"
                    class="form-control"
                    name="title"
                    placeholder="Database class"
                    maxlength="150"
                    required
                >

            </div>


            <div class="col-md-3">

                <label class="form-label">
                    Class
                </label>

                <input
                    type="text"
                    class="form-control"
                    name="class_name"
                    value="ITD010A"
                    maxlength="80"
                    required
                >

            </div>


            <div class="col-md-4">

                <label class="form-label">
                    Start Time
                </label>

                <input
                    type="datetime-local"
                    class="form-control"
                    name="starts_at"
                    value="<?= e(date('Y-m-d\TH:i')) ?>"
                    required
                >

            </div>

        </div>


        <div class="form-text mt-2">

            Students can check in from the start time until the admin
            manually closes the session.

        </div>


        <button type="submit" class="btn btn-primary mt-3">

            <i class="bi bi-plus-lg me-1"></i>

            Generate Attendance QR

        </button>

    </form>

</div>


<!-- QR CODE DISPLAY -->

<?php if ($qrUrl): ?>

<div class="panel mb-4 text-center p-4">

    <h4><?= e($qrTitle) ?></h4>


    <?php if ($qrType === 'permission'): ?>

        <span class="badge bg-info text-dark">
            Permission QR
        </span>

        <p class="text-muted mt-2">
            Students scan this QR to submit a permission request.
        </p>

    <?php else: ?>

        <span class="badge bg-primary">
            Attendance QR
        </span>

        <p class="text-muted mt-2">
            Students scan this QR to record Present.
        </p>

    <?php endif; ?>


    <div
        id="qrcode"
        class="qr-display mx-auto my-3"
    ></div>


    <div class="d-flex flex-wrap justify-content-center gap-2">

        <a
            class="btn btn-outline-primary"
            target="_blank"
            rel="noopener"
            href="<?= e($qrUrl) ?>"
        >
            <i class="bi bi-box-arrow-up-right me-1"></i>
            Test QR Link
        </a>


        <button
            type="button"
            class="btn btn-outline-secondary"
            onclick="copyQRLink()"
        >
            <i class="bi bi-clipboard me-1"></i>
            Copy QR Link
        </button>


        <button
            type="button"
            class="btn btn-outline-success"
            onclick="downloadQR()"
        >
            <i class="bi bi-download me-1"></i>
            Download QR
        </button>

    </div>


    <div class="small text-break mt-3" id="qr-link">
        <?= e($qrUrl) ?>
    </div>

</div>


<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<script>

const qrElement = document.getElementById('qrcode');

const qrText = <?= json_encode(
    $qrUrl,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;

if (typeof QRCode !== 'undefined') {

    new QRCode(qrElement, {
        text: qrText,
        width: 240,
        height: 240,
        correctLevel: QRCode.CorrectLevel.H
    });

} else {

    qrElement.innerHTML =
        '<div class="alert alert-danger">QR library could not load. Check your internet connection.</div>';

}


function copyQRLink() {

    if (navigator.clipboard && window.isSecureContext) {

        navigator.clipboard.writeText(qrText)
            .then(() => alert('QR Link copied successfully.'))
            .catch(() => prompt('Copy QR Link:', qrText));

    } else {

        prompt('Copy QR Link:', qrText);

    }

}


function downloadQR() {

    const canvas = qrElement.querySelector('canvas');

    const image = qrElement.querySelector('img');

    let dataUrl = '';

    if (canvas) {

        dataUrl = canvas.toDataURL('image/png');

    } else if (image && image.src) {

        dataUrl = image.src;

    }

    if (!dataUrl) {

        alert('QR Code is not ready yet.');

        return;

    }

    const link = document.createElement('a');

    link.href = dataUrl;

    link.download = 'attendance-qr.png';

    link.click();

}

</script>

<?php endif; ?>


<!-- SESSION HISTORY -->

<div class="panel">

    <div class="panel-head">

        <h5>
            <i class="bi bi-clock-history me-2"></i>
            Session History
        </h5>

    </div>


    <div class="table-responsive">

        <table class="table align-middle mb-0">

            <thead>

                <tr>

                    <th>Title</th>

                    <th>Class</th>

                    <th>Start Time</th>

                    <th>Status</th>

                    <th>QR Management</th>

                </tr>

            </thead>


            <tbody>

            <?php foreach ($rows as $r): ?>

                <tr>

                    <td class="fw-semibold">
                        <?= e($r['title']) ?>
                    </td>


                    <td>
                        <?= e($r['class_name']) ?>
                    </td>


                    <td>
                        <?= e($r['starts_at']) ?>
                    </td>


                    <td>

                        <?php if ($r['status'] === 'closed'): ?>

                            <span class="status closed">
                                Closed
                            </span>

                        <?php else: ?>

                            <span class="status open">
                                Open
                            </span>

                        <?php endif; ?>

                    </td>


                    <td>

                        <?php if ($r['status'] === 'open'): ?>


                            <!-- PERMISSION QR -->

                            <form method="POST" class="mb-2">

                                <input
                                    type="hidden"
                                    name="csrf"
                                    value="<?= e(csrf_token()) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="permission_qr"
                                >

                                <input
                                    type="hidden"
                                    name="session_id"
                                    value="<?= (int)$r['id'] ?>"
                                >

                                <button
                                    type="submit"
                                    class="btn btn-sm btn-outline-info"
                                >

                                    <i class="bi bi-qr-code me-1"></i>

                                    Generate Permission QR

                                </button>

                            </form>


                            <!-- CLOSE SESSION -->

                            <form
                                method="POST"
                                onsubmit="return confirm('Close this session and mark students without attendance as absent?')"
                            >

                                <input
                                    type="hidden"
                                    name="csrf"
                                    value="<?= e(csrf_token()) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="close"
                                >

                                <input
                                    type="hidden"
                                    name="session_id"
                                    value="<?= (int)$r['id'] ?>"
                                >

                                <button
                                    type="submit"
                                    class="btn btn-sm btn-outline-danger"
                                >

                                    <i class="bi bi-lock me-1"></i>

                                    Close Session

                                </button>

                            </form>


                        <?php else: ?>

                            <span class="text-muted small">
                                Session closed
                            </span>

                        <?php endif; ?>

                    </td>

                </tr>

            <?php endforeach; ?>


            <?php if (!$rows): ?>

                <tr>

                    <td
                        colspan="5"
                        class="text-center text-muted py-4"
                    >

                        No sessions created yet.

                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<?php page_footer(); ?>
