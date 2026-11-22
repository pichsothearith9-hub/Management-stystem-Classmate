
<?php
require __DIR__ . '/app.php';

$errors = [];
$successCode = '';
$successName = '';

$categories = [
    'Attendance',
    'Teacher',
    'Classroom',
    'Facilities',
    'Other'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $studentCode = trim($_POST['student_code'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($studentCode === '') {
        $errors[] = 'Please enter your Student ID.';
    }

    if (!in_array($category, $categories, true)) {
        $errors[] = 'Please select a valid category.';
    }

    if ($subject === '' || mb_strlen($subject) > 200) {
        $errors[] = 'Subject is required and must be under 200 characters.';
    }

    if ($description === '' || mb_strlen($description) > 5000) {
        $errors[] = 'Description is required and must be under 5000 characters.';
    }

    $student = null;

    if (!$errors) {
        $stmt = $pdo->prepare("
            SELECT id, full_name, student_code
            FROM users
            WHERE student_code = ?
              AND role = 'student'
              AND is_active = 1
            LIMIT 1
        ");

        $stmt->execute([$studentCode]);
        $student = $stmt->fetch();

        if (!$student) {
            $errors[] = 'Student ID was not found or the student is inactive.';
        }
    }

    if (!$errors && $student) {
        try {
            $insert = $pdo->prepare("
                INSERT INTO student_complaints
                    (complaint_code, student_id, category, subject, description)
                VALUES (?, ?, ?, ?, ?)
            ");

            // Generate a unique complaint code.
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $code = 'CMP-' . date('ymd') . '-' .
                    strtoupper(bin2hex(random_bytes(4)));

                try {
                    $insert->execute([
                        $code,
                        $student['id'],
                        $category,
                        $subject,
                        $description
                    ]);

                    $successCode = $code;
                    $successName = $student['full_name'];
                    break;

                } catch (PDOException $e) {
                    // Retry only if the complaint code collided.
                    if ($e->getCode() !== '23000') {
                        throw $e;
                    }
                }
            }

            if ($successCode === '') {
                throw new RuntimeException('Could not generate a complaint code.');
            }

        } catch (Throwable $e) {
            error_log('Complaint submission error: ' . $e->getMessage());
            $errors[] = 'Unable to submit your complaint. Please try again.';
        }
    }
}

page_header('Submit Complaint');
?>

<div class="container py-4" style="max-width:760px">

    <div class="mb-4">
        <h2 class="fw-bold">Submit a Complaint</h2>
        <p class="text-muted">
            Please provide accurate information. Your complaint will be
            reviewed by the administrator.
        </p>
    </div>

    <?php if ($successCode): ?>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4 text-center">

                <div class="text-success mb-3">
                    <i class="bi bi-check-circle-fill" style="font-size:3rem"></i>
                </div>

                <h3 class="fw-bold">Complaint Submitted!</h3>

                <p class="text-muted">
                    Thank you, <?= e($successName) ?>.
                    Your complaint has been received.
                </p>

                <div class="alert alert-success">
                    <div class="small">Your Complaint ID</div>
                    <h4 class="fw-bold mb-0">
                        <?= e($successCode) ?>
                    </h4>
                </div>

                <p class="small text-muted">
                    Please save this Complaint ID. You will need it
                    together with your Student ID to check the status.
                </p>

                <a href="my_complaints.php" class="btn btn-primary">
                    Check Complaint Status
                </a>

                <a href="complaint.php" class="btn btn-outline-secondary">
                    Submit Another Complaint
                </a>

            </div>
        </div>

    <?php else: ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">

                <form method="POST">

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= e(csrf_token()) ?>"
                    >

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Student ID
                        </label>

                        <input
                            type="text"
                            name="student_code"
                            class="form-control"
                            value="<?= e($_POST['student_code'] ?? '') ?>"
                            placeholder="Enter your Student ID"
                            maxlength="40"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Complaint Category
                        </label>

                        <select name="category" class="form-select" required>
                            <option value="">Select category</option>

                            <?php foreach ($categories as $item): ?>
                                <option
                                    value="<?= e($item) ?>"
                                    <?= (($_POST['category'] ?? '') === $item) ? 'selected' : '' ?>
                                >
                                    <?= e($item) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Subject
                        </label>

                        <input
                            type="text"
                            name="subject"
                            class="form-control"
                            maxlength="200"
                            value="<?= e($_POST['subject'] ?? '') ?>"
                            placeholder="Enter complaint subject"
                            required
                        >
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            Complaint Description
                        </label>

                        <textarea
                            name="description"
                            class="form-control"
                            rows="6"
                            maxlength="5000"
                            placeholder="Explain your complaint clearly..."
                            required
                        ><?= e($_POST['description'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-send me-2"></i>
                        Submit Complaint
                    </button>

                </form>

            </div>
        </div>

        <div class="text-center mt-3">
            <a href="my_complaints.php">
                Already submitted? Check your complaint
            </a>
        </div>

    <?php endif; ?>

</div>

<?php page_footer(); ?>
