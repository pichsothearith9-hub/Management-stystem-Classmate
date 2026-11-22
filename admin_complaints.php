
<?php
require __DIR__ . '/app.php';
require_admin();

$allowedStatuses = [
    'pending',
    'reviewing',
    'resolved',
    'rejected'
];

$errors = [];

// Update complaint
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $id = filter_input(INPUT_POST, 'complaint_id', FILTER_VALIDATE_INT);
    $status = $_POST['status'] ?? '';
    $reply = trim($_POST['admin_reply'] ?? '');

    if (!$id || !in_array($status, $allowedStatuses, true)) {
        $errors[] = 'Invalid complaint information.';
    }

    if (mb_strlen($reply) > 5000) {
        $errors[] = 'Admin reply must be under 5000 characters.';
    }

    if (!$errors) {
        $check = $pdo->prepare("
            SELECT id
            FROM student_complaints
            WHERE id = ?
            LIMIT 1
        ");

        $check->execute([$id]);

        if (!$check->fetch()) {
            $errors[] = 'Complaint not found.';
        } else {
            $update = $pdo->prepare("
                UPDATE student_complaints
                SET
                    status = ?,
                    admin_reply = ?,
                    reviewed_by = ?,
                    reviewed_at = CASE
                        WHEN ? = 'pending' THEN NULL
                        ELSE NOW()
                    END
                WHERE id = ?
            ");

            $update->execute([
                $status,
                $reply !== '' ? $reply : null,
                user()['id'],
                $status,
                $id
            ]);

            flash('Complaint updated successfully.', 'success');
            redirect('admin_complaints.php');
        }
    }
}

// Filter
$filter = $_GET['status'] ?? 'all';

if ($filter !== 'all' && !in_array($filter, $allowedStatuses, true)) {
    $filter = 'all';
}

$sql = "
    SELECT
        c.*,
        u.full_name,
        u.student_code
    FROM student_complaints c
    INNER JOIN users u ON u.id = c.student_id
";

$params = [];

if ($filter !== 'all') {
    $sql .= " WHERE c.status = ?";
    $params[] = $filter;
}

$sql .= " ORDER BY c.created_at DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$complaints = $stmt->fetchAll();

// Summary
$summary = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'pending') AS pending,
        SUM(status = 'reviewing') AS reviewing,
        SUM(status = 'resolved') AS resolved,
        SUM(status = 'rejected') AS rejected
    FROM student_complaints
")->fetch();

page_header('Student Complaints');
?>

<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="fw-bold mb-1">Student Complaints</h2>
            <p class="text-muted mb-0">
                Review and manage student complaints.
            </p>
        </div>

        <a href="complaint.php" target="_blank" class="btn btn-outline-primary">
            <i class="bi bi-plus-circle me-1"></i>
            Open Student Form
        </a>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">

        <?php
        $cards = [
            ['Total', $summary['total'] ?? 0, 'primary'],
            ['Pending', $summary['pending'] ?? 0, 'warning'],
            ['Reviewing', $summary['reviewing'] ?? 0, 'info'],
            ['Resolved', $summary['resolved'] ?? 0, 'success'],
            ['Rejected', $summary['rejected'] ?? 0, 'danger']
        ];
        ?>

        <?php foreach ($cards as [$label, $number, $color]): ?>
            <div class="col-6 col-md">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="small text-muted"><?= e($label) ?></div>
                        <div class="fs-3 fw-bold text-<?= e($color) ?>">
                            <?= (int)$number ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">

            <form method="GET" class="row align-items-end g-2">

                <div class="col-md-4">
                    <label class="form-label">Filter by status</label>

                    <select name="status" class="form-select">
                        <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>
                            All Complaints
                        </option>

                        <?php foreach ($allowedStatuses as $status): ?>
                            <option
                                value="<?= e($status) ?>"
                                <?= $filter === $status ? 'selected' : '' ?>
                            >
                                <?= e(ucfirst($status)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-auto">
                    <button class="btn btn-primary">
                        <i class="bi bi-funnel me-1"></i>
                        Filter
                    </button>
                </div>

            </form>

        </div>
    </div>

    <?php if (!$complaints): ?>

        <div class="alert alert-info">
            No complaints found.
        </div>

    <?php else: ?>

        <?php foreach ($complaints as $c): ?>

            <div class="card border-0 shadow-sm mb-3">

                <div class="card-body p-4">

                    <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">

                        <div>
                            <span class="fw-bold">
                                <?= e($c['complaint_code']) ?>
                            </span>

                            <div class="small text-muted">
                                <?= e($c['full_name']) ?>
                                · Student ID: <?= e($c['student_code']) ?>
                            </div>
                        </div>

                        <?php
                        $statusColors = [
                            'pending' => 'warning',
                            'reviewing' => 'info',
                            'resolved' => 'success',
                            'rejected' => 'danger'
                        ];

                        $color = $statusColors[$c['status']] ?? 'secondary';
                        ?>

                        <span class="badge text-bg-<?= e($color) ?> align-self-start">
                            <?= e(ucfirst($c['status'])) ?>
                        </span>

                    </div>

                    <div class="mb-2">
                        <span class="text-muted small">Category:</span>
                        <?= e($c['category']) ?>
                    </div>

                    <h5 class="fw-bold">
                        <?= e($c['subject']) ?>
                    </h5>

                    <p style="white-space:pre-wrap"><?= e($c['description']) ?></p>

                    <div class="small text-muted mb-3">
                        Submitted:
                        <?= e($c['created_at']) ?>
                    </div>

                    <hr>

                    <form method="POST">

                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= e(csrf_token()) ?>"
                        >

                        <input
                            type="hidden"
                            name="complaint_id"
                            value="<?= (int)$c['id'] ?>"
                        >

                        <div class="row g-3">

                            <div class="col-md-4">
                                <label class="form-label fw-semibold">
                                    Update Status
                                </label>

                                <select name="status" class="form-select" required>
                                    <?php foreach ($allowedStatuses as $status): ?>
                                        <option
                                            value="<?= e($status) ?>"
                                            <?= $c['status'] === $status ? 'selected' : '' ?>
                                        >
                                            <?= e(ucfirst($status)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-8">
                                <label class="form-label fw-semibold">
                                    Admin Reply
                                </label>

                                <textarea
                                    name="admin_reply"
                                    class="form-control"
                                    rows="2"
                                    maxlength="5000"
                                    placeholder="Write a response to the student..."
                                ><?= e($c['admin_reply'] ?? '') ?></textarea>
                            </div>

                        </div>

                        <button type="submit" class="btn btn-primary mt-3">
                            <i class="bi bi-save me-1"></i>
                            Save Changes
                        </button>

                    </form>

                </div>
            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>

<?php page_footer(); ?>
