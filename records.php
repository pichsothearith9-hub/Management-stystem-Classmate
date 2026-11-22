<?php
require __DIR__.'/app.php'; require_admin();
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check();$id=(int)($_POST['attendance_id']??0);$status=$_POST['status']??'';$note=trim($_POST['note']??'');
 if(!in_array($status,['present','late','absent','permission'],true)){flash('Invalid status.','danger');redirect('records.php');}
 $pdo->beginTransaction();
 $q=$pdo->prepare("SELECT * FROM attendance WHERE id=? FOR UPDATE");$q->execute([$id]);$old=$q->fetch();
 if($old){$pdo->prepare("UPDATE attendance SET status=?,note=?,source='admin' WHERE id=?")->execute([$status,$note,$id]);$pdo->prepare("INSERT INTO attendance_logs(attendance_id,changed_by,old_status,new_status,note) VALUES(?,?,?,?,?)")->execute([$id,user()['id'],$old['status'],$status,$note]);}
 $pdo->commit();flash('Attendance updated and change logged.');redirect('records.php');
}
$rows=$pdo->query("SELECT a.*,u.full_name,u.student_code,s.title,s.class_name,s.starts_at FROM attendance a JOIN users u ON u.id=a.student_id JOIN attendance_sessions s ON s.id=a.session_id ORDER BY s.starts_at DESC,u.full_name LIMIT 300")->fetchAll();
page_header('Attendance Records');
?>
<div class="welcome-row"><div><h2>Attendance records</h2><p class="text-muted">Review and manually correct attendance. All admin changes are logged.</p></div></div>
<div class="panel"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Student</th><th>Session</th><th>Date / Check-in</th><th>Status</th><th>Admin correction</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><strong><?=e($r['full_name'])?></strong><div class="small text-muted"><?=e($r['student_code']??'')?></div></td><td><?=e($r['title'])?><div class="small text-muted"><?=e($r['class_name'])?></div></td><td><?=e($r['starts_at'])?><div class="small text-muted"><?=e($r['checked_in_at']??'—')?></div></td><td><span class="status <?=e($r['status'])?>"><?=e($r['status'])?></span></td><td><form method="post" class="d-flex gap-1 flex-wrap"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="attendance_id" value="<?=$r['id']?>"><select class="form-select form-select-sm" name="status" style="max-width:125px"><option value="present" <?=$r['status']==='present'?'selected':''?>>Present</option><option value="late" <?=$r['status']==='late'?'selected':''?>>Late</option><option value="absent" <?=$r['status']==='absent'?'selected':''?>>Absent</option><option value="permission" <?=$r['status']==='permission'?'selected':''?>>Permission</option></select><input class="form-control form-control-sm" name="note" placeholder="Reason / note" value="<?=e($r['note'])?>" style="max-width:140px"><button class="btn btn-sm btn-primary">Save</button></form></td></tr><?php endforeach;?></tbody></table></div></div>
<?php page_footer(); ?>
