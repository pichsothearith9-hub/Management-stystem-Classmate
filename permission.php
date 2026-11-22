
<?php
require __DIR__ . '/app.php';

// ============================================
// APPROVE / REJECT PERMISSION REQUEST
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_check();

    $requestId = (int)($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if (
        $requestId <= 0 ||
        !in_array($action, ['approve', 'reject'], true)
    ) {
        flash('Invalid permission request.', 'danger');
        redirect('admin_permissions.php');
    }

    try {

        $pdo->beginTransaction();

        // Lock only the required request fields
        $stmt = $pdo->prepare("
            SELECT
                id,
                session_id,
                student_id,
                reason,
                status
            FROM permission_requests
            WHERE id = ?
            FOR UPDATE
        ");

        $stmt->execute([$requestId]);

        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            throw new RuntimeException(
                'Permission request not found.'
            );
        }

        if ($request['status'] !== 'pending') {
            throw new RuntimeException(
                'This request has already been reviewed.'
            );
        }

        // ====================================
        // APPROVE REQUEST
        // ====================================

        if ($action === 'approve') {

            // Check existing attendance
            $attendanceStmt = $pdo->prepare("
                SELECT id
                FROM attendance
                WHERE session_id = ?
                  AND student_id = ?
                LIMIT 1
                FOR UPDATE
            ");

            $attendanceStmt->execute([
                $request['session_id'],
                $request['student_id']
            ]);

            $attendance = $attendanceStmt->fetch(
                PDO::FETCH_ASSOC
            );

            if ($attendance) {

                // Permission overrides present / late / absent
                $updateAttendance = $pdo->prepare("
                    UPDATE attendance
                    SET
                        status = 'permission',
                        note = ?,
                        source = 'admin'
                    WHERE id = ?
                ");

                $updateAttendance->execute([
                    $request['reason'],
                    $attendance['id']
                ]);

            } else {

                // Create new permission attendance
                $insertAttendance = $pdo->prepare("
                    INSERT INTO attendance (
                        session_id,
                        student_id,
                        status,
                        note,
                        source
                    )
                    VALUES (?, ?, 'permission', ?, 'admin')
                ");

                $insertAttendance->execute([
                    $request['session_id'],
                    $request['student_id'],
                    $request['reason']
                ]);
            }

            $newStatus = 'approved';

        } else {

            // =================================
            // REJECT REQUEST
            // =================================

            $newStatus = 'rejected';
        }

        // ====================================
        // UPDATE REQUEST STATUS
        // ====================================

        $reviewStmt = $pdo->prepare("
            UPDATE permission_requests
            SET
                status = ?,
                reviewed_by = ?,
                reviewed_at = NOW()
            WHERE id = ?
              AND status = 'pending'
        ");

        $reviewStmt->execute([
            $newStatus,
            user()['id'],
            $requestId
        ]);

        if ($reviewStmt->rowCount() !== 1) {
            throw new RuntimeException(
                'Unable to update permission request.'
            );
        }

        $pdo->commit();

        if ($action === 'approve') {

            flash(
                'Permission approved successfully. Attendance updated.',
                'success'
            );

        } else {

            flash(
                'Permission request rejected successfully.',
                'success'
            );
        }

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'Permission Review Error: ' . $e->getMessage()
        );

        // Do not expose database errors to users
        flash(
            'Unable to process this request. Please check the system log.',
            'danger'
        );
    }

    redirect('admin_permissions.php');
}

// ============================================
// PAGINATION
// ============================================

$perPage = 50;

$currentPage = max(
    1,
    (int)($_GET['page'] ?? 1)
);

// ============================================
// COUNT REQUESTS BY STATUS
// ============================================

$countStmt = $pdo->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END),
            0
        ) AS pending_count,
        COALESCE(
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END),
            0
        ) AS approved_count,
        COALESCE(
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END),
            0
        ) AS rejected_count
    FROM permission_requests
");

$counts = $countStmt->fetch(PDO::FETCH_ASSOC);

$totalRequests = (int)$counts['total'];
$pendingCount = (int)$counts['pending_count'];
$approvedCount = (int)$counts['approved_count'];
$rejectedCount = (int)$counts['rejected_count'];

$totalPages = max(
    1,
    (int)ceil($totalRequests / $perPage)
);

$currentPage = min($currentPage, $totalPages);

$offset = ($currentPage - 1) * $perPage;

// ============================================
// GET PERMISSION REQUESTS
// LIMIT 50 TO REDUCE MEMORY USAGE
// ============================================

$stmt = $pdo->prepare("
    SELECT
        pr.id,
        pr.session_id,
        pr.student_id,
        pr.reason,
        pr.status,
        pr.request_type,
        pr.reviewed_by,
        pr.reviewed_at,
        pr.created_at,

        u.student_code,
        u.full_name,

        s.title AS session_title,
        s.class_name

    FROM permission_requests pr

    INNER JOIN users u
        ON u.id = pr.student_id

    INNER JOIN attendance_sessions s
        ON s.id = pr.session_id

    ORDER BY
        CASE pr.status
            WHEN 'pending' THEN 0
            WHEN 'approved' THEN 1
            ELSE 2
        END,
        pr.created_at DESC,
        pr.id DESC

    LIMIT :limit OFFSET :offset
");

$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();

$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// PAGE
// ============================================

page_header('Permission Requests');

?>

<!-- PAGE HEADER -->

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">

    <div>
        <h4 class="fw-bold mb-1">
            Permission Requests
        </h4>

        <p class="text-muted mb-0">
            Review and manage student permission requests.
        </p>
    </div>

    <span class="badge bg-primary fs-6">
        <?= number_format($totalRequests) ?> Total Requests
    </span>

</div>

<!-- ========================================
     STATUS CARDS
========================================= -->

<div class="row g-3 mb-4">

    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body">

                <div class="text-muted small">
                    Pending
                </div>

                <h3 class="fw-bold text-warning mb-0">
                    <?= number_format($pendingCount) ?>
                </h3>

            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body">

                <div class="text-muted small">
                    Approved
                </div>

                <h3 class="fw-bold text-success mb-0">
                    <?= number_format($approvedCount) ?>
                </h3>

            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body">

                <div class="text-muted small">
                    Rejected
                </div>

                <h3 class="fw-bold text-danger mb-0">
                    <?= number_format($rejectedCount) ?>
                </h3>

            </div>
        </div>
    </div>

</div>

<!-- ========================================
     REQUEST LIST
========================================= -->

<?php if (empty($requests)): ?>

    <div class="card border-0 shadow-sm rounded-4">

        <div class="card-body text-center py-5">

            <i
                class="bi bi-inbox text-muted"
                style="font-size:48px;"
            ></i>

            <h5 class="mt-3">
                No Permission Requests
            </h5>

            <p class="text-muted mb-0">
                Student permission requests will appear here.
            </p>

        </div>

    </div>

<?php else: ?>

    <div class="row g-3">

        <?php foreach ($requests as $r): ?>

            <?php

            // Safe status badge
            $badge = 'danger';

            if ($r['status'] === 'pending') {
                $badge = 'warning';
            } elseif ($r['status'] === 'approved') {
                $badge = 'success';
            }

            ?>

            <div class="col-12">

                <div class="card border-0 shadow-sm rounded-4">

                    <div class="card-body p-4">

                        <!-- STUDENT INFORMATION -->

                        <div class="d-flex flex-wrap justify-content-between gap-2">

                            <div>

                                <h5 class="fw-bold mb-1">
                                    <?= e($r['full_name']) ?>
                                </h5>

                                <div class="text-muted small">
                                    Student ID:
                                    <?= e($r['student_code'] ?? '-') ?>
                                </div>

                            </div>

                            <span class="badge bg-<?= $badge ?> align-self-start">
                                <?= e(ucfirst($r['status'])) ?>
                            </span>

                        </div>

                        <hr>

                        <!-- SESSION INFORMATION -->

                        <div class="row g-3">

                            <div class="col-md-6">

                                <div class="small text-muted">
                                    Attendance Session
                                </div>

                                <div class="fw-semibold">
                                    <?= e($r['session_title']) ?>
                                </div>

                                <div class="small text-muted">
                                    Class:
                                    <?= e($r['class_name']) ?>
                                </div>

                            </div>

                            <div class="col-md-6">

                                <div class="small text-muted">
                                    Request Type
                                </div>

                                <div class="fw-semibold">
                                    <?= e(ucfirst($r['request_type'] ?? 'student')) ?>
                                </div>

                                <div class="small text-muted mt-2">
                                    Submitted At
                                </div>

                                <div>
                                    <?= e($r['created_at']) ?>
                                </div>

                            </div>

                        </div>

                        <!-- REASON -->

                        <div class="mt-3">

                            <div class="small text-muted mb-1">
                                Reason for Permission
                            </div>

                            <div class="bg-light rounded-3 p-3">
                                <?= nl2br(e($r['reason'])) ?>
                            </div>

                        </div>

                        <!-- REVIEW INFORMATION -->

                        <?php if ($r['status'] !== 'pending'): ?>

                            <div class="mt-3 small text-muted">

                                Reviewed At:
                                <?= e($r['reviewed_at'] ?? '-') ?>

                            </div>

                        <?php endif; ?>

                        <!-- APPROVE / REJECT -->

                        <?php if ($r['status'] === 'pending'): ?>

                            <form method="POST" class="mt-4">

                                <input
                                    type="hidden"
                                    name="csrf"
                                    value="<?= e(csrf_token()) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="request_id"
                                    value="<?= (int)$r['id'] ?>"
                                >

                                <div class="d-flex flex-wrap gap-2">

                                    <button
                                        type="submit"
                                        name="action"
                                        value="approve"
                                        class="btn btn-success"
                                        onclick="return confirm('Are you sure you want to approve this request?')"
                                    >

                                        <i class="bi bi-check-circle me-1"></i>
                                        Approve

                                    </button>

                                    <button
                                        type="submit"
                                        name="action"
                                        value="reject"
                                        class="btn btn-outline-danger"
                                        onclick="return confirm('Are you sure you want to reject this request?')"
                                    >

                                        <i class="bi bi-x-circle me-1"></i>
                                        Reject

                                    </button>

                                </div>

                            </form>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        <?php endforeach; ?>

    </div>

    <!-- ====================================
         PAGINATION
    ===================================== -->

    <?php if ($totalPages > 1): ?>

        <nav class="mt-4" aria-label="Permission request pages">

            <ul class="pagination justify-content-center flex-wrap">

                <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">

                    <a
                        class="page-link"
                        href="?page=<?= max(1, $currentPage - 1) ?>"
                    >
                        Previous
                    </a>

                </li>

                <?php

                $startPage = max(1, $currentPage - 2);
                $endPage = min($totalPages, $currentPage + 2);

                ?>

                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>

                    <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">

                        <a
                            class="page-link"
                            href="?page=<?= $i ?>"
                        >
                            <?= $i ?>
                        </a>

                    </li>

                <?php endfor; ?>

                <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">

                    <a
                        class="page-link"
                        href="?page=<?= min($totalPages, $currentPage + 1) ?>"
                    >
                        Next
                    </a>

                </li>

            </ul>

            <p class="text-muted text-center small">
                Page <?= $currentPage ?> of <?= $totalPages ?>
                · Showing <?= count($requests) ?> requests
            </p>

        </nav>

    <?php endif; ?>

<?php endif; ?>

<?php page_footer(); ?>
