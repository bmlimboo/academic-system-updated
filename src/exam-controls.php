<?php
function examStaffPreference(int $id):array{return one('SELECT * FROM exam_staff_preferences WHERE staff_id=?',[$id])?:['enabled'=>1,'reason'=>''];}
function examNoticeSubjects():array{return ['Notification: Question Paper Submission Now Open','Reminder: Pending Question Paper Submission','Submission Deadline Closed: Question Paper Not Submitted','Question Paper Submission Period Updated'];}
function examCancelNotices(?int $window=null,?string $email=null):void{
 $sql="UPDATE outbox SET status='superseded' WHERE status IN ('queued','failed') AND subject IN (?,?,?,?) AND id IN (SELECT outbox_id FROM exam_mail_windows"; $p=examNoticeSubjects();if($window!==null){$sql.=' WHERE window_id=?';$p[]=$window;}$sql.=')';if($email!==null){$sql.=' AND recipient=?';$p[]=$email;}q($sql,$p);
}
function examNoticeAllowed(array $mail):bool{
 if(!in_array($mail['subject'],examNoticeSubjects(),true)||!one('SELECT outbox_id FROM exam_mail_windows WHERE outbox_id=?',[$mail['id']]))return true;
 $u=one('SELECT id,active FROM users WHERE email=?',[$mail['recipient']]);$pref=$u?one('SELECT enabled FROM exam_staff_preferences WHERE staff_id=?'.(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[$u['id']]):null;return $u&&!empty($u['active'])&&(!$pref||!empty($pref['enabled']));
}
function examEditPeriod(array $actor,array $p):string{
 $w=examLock((int)($p['window_id']??0));$start=examDateTime(required($p,'opens_at'));$end=examDateTime(required($p,'closes_at'));if($end<=$start)throw new RuntimeException('Deadline must be after opening time.');
 $reason=required($p,'reason');if(mb_strlen($reason)>500)throw new RuntimeException('Reason must be no longer than 500 characters.');
 if(($p['previous_opens_at']??'')!==$w['opens_at']||($p['previous_closes_at']??'')!==$w['closes_at'])throw new RuntimeException('Another user changed this period. Refresh before editing.');
 $reopenRequested=!empty($p['reopen_round']);if($reopenRequested&&(!$w['published']||$w['closes_at']>now()||$end<=now()))throw new RuntimeException('A new submission round can be opened only after the previous published deadline has passed, with a future closing time.');$reopened=$reopenRequested;$round=max(1,(int)($w['submission_round']??1))+($reopened?1:0);
 q('UPDATE exam_windows SET opens_at=?,closes_at=?,submission_round=?,late_enabled=? WHERE id=?',[$start,$end,$round,$reopened?0:(int)$w['late_enabled'],$w['id']]);examCancelNotices((int)$w['id']);
 $updated=one('SELECT * FROM exam_windows WHERE id=?',[$w['id']]);if($w['published'])examWindowNotifications($updated);
 if(!empty($p['notify_staff'])&&$w['published']){global $config;foreach(examTeachers(true)as $u)examMail($u['email'],'Question Paper Submission Period Updated',"Dear {$u['name']},\n\nExam Cell has updated the submission period for {$w['academic_year']} / {$w['semester']} / {$w['exam_type']}.\nOpens: $start\nDeadline: $end\nAll times are Bhutan time.\n\n".rtrim($config['url'],'/').'/?page=exam_submit&exam_id='.$w['id'],(int)$w['id']);}
 examAudit((int)$actor['id'],$reopened?'Submission period reopened':'Submission period dates edited',['window'=>$w['id'],'before'=>[$w['opens_at'],$w['closes_at']],'after'=>[$start,$end],'submission_round'=>$round,'reopened'=>$reopened,'reason'=>$reason,'notify'=>!empty($p['notify_staff'])]);
 return $reopened?"Submission period reopened as round $round. Staff may resubmit previously submitted modules until the new deadline. Earlier PDFs remain in the archive. The opening notification will be queued when the new opening time is reached.":'Dates updated. Existing submissions retained. Outdated unsent notices cancelled. '.(!empty($p['notify_staff'])&&$w['published']?'One update notification queued for enabled staff.':'No update email sent.');
}
function examSetStaffPreference(array $actor,array $p):string{
 $id=(int)($p['staff_id']??0);$u=one('SELECT * FROM users WHERE id=?',[$id]);if(!$u||!in_array($id,array_map(fn($v)=>(int)$v['id'],examTeachers()),true))throw new RuntimeException('Select an active teaching staff member.');
 $enabled=($p['enabled']??'')==='1'?1:0;$reason=required($p,'reason');if(mb_strlen($reason)>500)throw new RuntimeException('Reason must be no longer than 500 characters.');
 $old=examStaffPreference($id);if(one('SELECT staff_id FROM exam_staff_preferences WHERE staff_id=?',[$id]))q('UPDATE exam_staff_preferences SET enabled=?,reason=?,updated_by=?,updated_at=? WHERE staff_id=?',[$enabled,$reason,$actor['id'],now(),$id]);else q('INSERT INTO exam_staff_preferences(staff_id,enabled,reason,updated_by,updated_at) VALUES(?,?,?,?,?)',[$id,$enabled,$reason,$actor['id'],now()]);
 if(!$enabled)examCancelNotices(null,$u['email']);examAudit((int)$actor['id'],'Question paper notifications changed',['staff'=>$id,'before'=>$old,'enabled'=>$enabled,'reason'=>$reason]);return $enabled?'Staff enabled for future question paper notices. No past emails resent.':'Staff excluded from question paper notices and pending counts. Account and submitted papers retained.';
}
