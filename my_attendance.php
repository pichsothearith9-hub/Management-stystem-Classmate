<?php
require __DIR__.'/app.php';require_login();if(user()['role']!=='student')redirect('dashboard.php');
$q=$pdo->prepare("SELECT a.*,s.title,s.class_name,s.starts_at FROM attendance a JOIN attendance_sessions s ON s.id=a.session_id WHERE a.student_id=? ORDER BY s.starts_at DESC");$q->execute([user()['id']]);$rows=$q->fetchAll();
page_header('My Attendance');
?>
<div class="welcome-row"><div><h2>My attendance</h2><p class="text-muted">Your personal attendance history.</p></div></div><div class="panel"><div class="table-responsive"><table class="table"><thead><tr><th>Session</th><th>Class</th><th>Date</th><th>Check-in</th><th>Status</th><th>Note</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['title'])?></td><td><?=e($r['class_name'])?></td><td><?=e($r['starts_at'])?></td><td><?=e($r['checked_in_at']??'—')?></td><td><span class="status <?=e($r['status'])?>"><?=e($r['status'])?></span></td><td><?=e($r['note'])?></td></tr><?php endforeach;?></tbody></table></div></div>
<?php page_footer(); ?>
