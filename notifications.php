
<?php

require __DIR__ . '/app.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_check();

    $action = $_POST['action'] ?? '';

    if ($action === 'read') {

        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {

            $stmt = $pdo->prepare("
                UPDATE attendance_notifications
                SET is_read = 1
                WHERE id = ?
            ");

            $stmt->execute([$id]);
        }

    } elseif ($action === 'read_all') {

        $pdo->exec("
            UPDATE attendance_notifications
            SET is_read = 1
            WHERE is_read = 0
        ");
    }

    redirect('notifications.php');
}

// Unread count
$unread = (int)$pdo->query("
    SELECT COUNT(*)
    FROM attendance_notifications
    WHERE is_read = 0
")->fetchColumn();

// Total count
$total = (int)$pdo->query("
    SELECT COUNT(*)
    FROM attendance_notifications
")->fetchColumn();

// Notification list
$stmt = $pdo->query("
    SELECT
        n.*,
        u.full_name,
        u.student_code,
        s.title AS session_title,
        s.class_name
    FROM attendance_notifications n
    JOIN users u
        ON u.id = n.student_id
    JOIN attendance_sessions s
        ON s.id = n.session_id
    ORDER BY n.id DESC
    LIMIT 100
");

$notifications = $stmt->fetchAll();

page_header('Notifications');
?>

<div class="welcome-row">

    <div>
        <h2>
            <i class="bi bi-bell me-2"></i>
            Notifications
        </h2>

        <p class="text-muted mb-0">
            Student QR attendance notifications.
        </p>
    </div>

    <?php if ($unread > 0): ?>

        <form method="post">

            <input
                type="hidden"
                name="csrf"
                value="<?= e(csrf_token()) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="read_all"
            >

            <button class="btn btn-outline-primary">
                <i class="bi bi-check2-all me-1"></i>
                Mark All as Read
            </button>

        </form>

    <?php endif; ?>

</div>

<div class="row g-3 mt-2">

    <div class="col-md-6">

        <div class="stat-card">

            <span class="stat-icon blue">
                <i class="bi bi-bell"></i>
            </span>

            <div>
                <small>Unread Notifications</small>
                <h3><?= $unread ?></h3>
            </div>

        </div>

    </div>

    <div class="col-md-6">

        <div class="stat-card">

            <span class="stat-icon green">
                <i class="bi bi-check-circle"></i>
            </span>

            <div>
                <small>Total Notifications</small>
                <h3><?= $total ?></h3>
            </div>

        </div>

    </div>

</div>

<div class="panel mt-4">

    <div class="panel-head">
        <h5>Attendance Notifications</h5>
        <a href="dashboard.php">Back to Dashboard</a>
    </div>

    <div class="list-group list-group-flush">

        <?php foreach ($notifications as $n): ?>

            <div class="list-group-item p-3
                <?= !$n['is_read'] ? 'bg-light' : '' ?>">

                <div class="d-flex justify-content-between flex-wrap gap-3">

                    <div class="flex-grow-1">

                        <div class="d-flex align-items-center gap-2">

                            <i class="bi bi-person-check-fill text-success fs-5"></i>

                            <strong>
                                <?= e($n['full_name']) ?>
                            </strong>

                            <?php if (!$n['is_read']): ?>
                                <span class="badge bg-primary">NEW</span>
                            <?php endif; ?>

                        </div>

                        <p class="mb-1 mt-2">
                            <?= e($n['message']) ?>
                        </p>

                        <div class="small text-muted">
                            Student ID:
                            <?= e($n['student_code'] ?? '') ?>
                        </div>

                        <div class="small text-muted">
                            Session:
                            <?= e($n['session_title']) ?>
                        </div>

                        <div class="small text-muted">
                            Class:
                            <?= e($n['class_name']) ?>
                        </div>

                        <div class="small text-muted mt-2">
                            <i class="bi bi-clock me-1"></i>
                            <?= e($n['created_at']) ?>
                        </div>

                    </div>

                    <?php if (!$n['is_read']): ?>

                        <div>

                            <form method="post">

                                <input
                                    type="hidden"
                                    name="csrf"
                                    value="<?= e(csrf_token()) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="read"
                                >

                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= (int)$n['id'] ?>"
                                >

                                <button class="btn btn-sm btn-outline-success">
                                    <i class="bi bi-check2"></i>
                                    Mark Read
                                </button>

                            </form>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        <?php endforeach; ?>

        <?php if (!$notifications): ?>

            <div class="text-center text-muted py-5">

                <i class="bi bi-bell-slash fs-1"></i>

                <h5 class="mt-3">
                    No Notifications
                </h5>

                <p class="mb-0">
                    New QR check-ins will appear here.
                </p>

            </div>

        <?php endif; ?>

    </div>

</div>

<?php page_footer(); ?>
