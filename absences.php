
<?php
require __DIR__ . '/app.php';
require_admin();

$pageTitle = 'Absence Management';

// Filter
$date = trim($_GET['date'] ?? date('Y-m-d'));
$class = trim($_GET['class'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

// Get available classes
$classStmt = $pdo->query("
    SELECT DISTINCT class_name
    FROM attendance_sessions
    WHERE class_name IS NOT NULL
      AND class_name <> ''
    ORDER BY class_name
");
$classes = $classStmt->fetchAll(PDO::FETCH_COLUMN);

// Get absent students
$sql = "
    SELECT
        a.id AS attendance_id,
        a.status,
        a.note,
        a.created_at,
        u.id AS student_id,
        u.student_code,
        u.full_name,
        s.title AS session_title,
        s.class_name,
        s.starts_at
    FROM attendance a
    INNER JOIN users u ON u.id = a.student_id
    INNER JOIN attendance_sessions s ON s.id = a.session_id
    WHERE a.status = 'absent'
      AND DATE(s.starts_at) = ?
";

$params = [$date];

if ($class !== '') {
    $sql .= " AND s.class_name = ?";
    $params[] = $class;
}

$sql .= " ORDER BY s.starts_at DESC, u.full_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$absences = $stmt->fetchAll(PDO::FETCH_ASSOC);

page_header('Absence Management');
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h3 class="fw-bold mb-1">Absence Management</h3>
        <p class="text-secondary mb-0">
            View students recorded as absent after attendance sessions close.
        </p>
    </div>
    <span class="badge text-bg-danger fs-6">
        <?= count($absences) ?> Absent
    </span>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">

            <div class="col-md-4">
                <label class="form-label fw-semibold">Attendance Date</label>
                <input
                    type="date"
                    name="date"
                    class="form-control"
                    value="<?= e($date) ?>"
                >
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Class</label>
                <select name="class" class="form-select">
                    <option value="">All Classes</option>

                    <?php foreach ($classes as $item): ?>
                        <option
                            value="<?= e($item) ?>"
                            <?= $class === $item ? 'selected' : '' ?>
                        >
                            <?= e($item) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search me-1"></i> Filter
                </button>

                <a href="absences.php" class="btn btn-outline-secondary">
                    Reset
                </a>
            </div>

        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h5 class="fw-bold mb-0">
            <i class="bi bi-person-x text-danger me-2"></i>
            Absent Students
        </h5>
    </div>

    <div class="card-body p-0">
        <?php if (!$absences): ?>

            <div class="text-center py-5">
                <i class="bi bi-calendar-check fs-1 text-secondary"></i>
                <h5 class="mt-3">No Absence Records</h5>
                <p class="text-secondary mb-0">
                    No absent students were found for this date.
                </p>
            </div>

        <?php else: ?>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">#</th>
                            <th>Student ID</th>
                            <th>Student Name</th>
                            <th>Class</th>
                            <th>Session</th>
                            <th>Start Time</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($absences as $index => $row): ?>
                            <tr>
                                <td class="ps-3">
                                    <?= $index + 1 ?>
                                </td>

                                <td>
                                    <span class="fw-semibold">
                                        <?= e($row['student_code'] ?? '-') ?>
                                    </span>
                                </td>

                                <td>
                                    <?= e($row['full_name']) ?>
                                </td>

                                <td>
                                    <?= e($row['class_name']) ?>
                                </td>

                                <td>
                                    <?= e($row['session_title']) ?>
                                </td>

                                <td>
                                    <?= e($row['starts_at']) ?>
                                </td>

                                <td>
                                    <span class="badge text-bg-danger">
                                        Absent
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </div>
</div>

<?php page_footer(); ?>
