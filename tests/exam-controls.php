<?php
$path=sys_get_temp_dir().'/exam-controls-'.bin2hex(random_bytes(5)).'.sqlite';putenv('APP_DSN=sqlite:'.$path);require __DIR__.'/../src/bootstrap.php';db()->exec(file_get_contents(__DIR__.'/../src/schema.sql'));db()->exec(file_get_contents(__DIR__.'/../migrations/007_exam_portal.sql'));require __DIR__.'/../bin/migrate-exam-programmes.php';require __DIR__.'/../bin/migrate-exam-controls.php';
$n=0;function ck($v,$m){global $n;if(!$v)throw new RuntimeException($m);$n++;}function deny($fn){try{$fn();}catch(RuntimeException $e){ck(true,'denied');return;}throw new RuntimeException('Expected denial');}
try{
foreach(['exam','faculty','faculty']as $i=>$role)q('INSERT INTO users(name,email,password,role,department) VALUES(?,?,?,?,?)',['Test '.$i,'test'.$i.'@example.test','unused',$role,departments()[0]]);
$exam=one('SELECT * FROM users WHERE id=1');$t=one('SELECT * FROM users WHERE id=2');
examPost($exam,'exam_window',['academic_year'=>'2026','semester'=>'I','exam_type'=>'End-semester Exam','opens_at'=>date('Y-m-d\TH:i',time()-3600),'closes_at'=>date('Y-m-d\TH:i',time()+86400*5)]);examPost($exam,'exam_publish',['window_id'=>1]);
ck((int)q('SELECT COUNT(*) FROM outbox')->fetchColumn()===2,'opening queued for two tutors');
examMail($t['email'],'Question Paper Submission Confirmation','receipt',1);$receipt=(int)db()->lastInsertId();
q("INSERT INTO exam_expected(window_id,staff_id,department,programme,module_code,module_title) VALUES(1,2,'Department of Agriculture','BSc. Agriculture','TEST101','Test')");$eid=(int)db()->lastInsertId();q('INSERT INTO exam_papers(expected_id,active_key,paper_path,staff_name,staff_email,submitted_at) VALUES(?,?,?,?,?,?)',[$eid,$eid,'fictional','Test','test1@example.test',now()]);
deny(fn()=>examPost($t,'exam_staff_preference',['staff_id'=>3,'enabled'=>'0','reason'=>'Leave']));
examPost($exam,'exam_staff_preference',['staff_id'=>2,'enabled'=>'0','reason'=>'Study leave']);
ck(!examStaffPreference(2)['enabled'],'excluded');ck((int)one('SELECT active FROM users WHERE id=2')['active']===1,'account active');ck(one('SELECT status FROM outbox WHERE id=?',[$receipt])['status']==='queued','receipt retained');ck(count(examTeachers(true))===1,'notices exclude staff');$r=examStaffReport(1,[]);ck($r['pending']===1&&$r['excluded']===1&&count($r['rows'])===2,'excluded visible but not pending');ck(examStaffReport(1,['teacher_notifications'=>'excluded'])['matches']===1,'excluded filter');
$m=one("SELECT * FROM outbox WHERE recipient=? AND subject=?",[$t['email'],examNoticeSubjects()[0]]);ck(!examNoticeAllowed($m),'worker suppresses excluded notice');
$w=one('SELECT * FROM exam_windows WHERE id=1');$p=['window_id'=>1,'opens_at'=>date('Y-m-d\TH:i',time()-3600),'closes_at'=>date('Y-m-d\TH:i',time()+86400*6),'previous_opens_at'=>$w['opens_at'],'previous_closes_at'=>$w['closes_at'],'reason'=>'Correction'];
$before=(int)q('SELECT COUNT(*) FROM outbox')->fetchColumn();examPost($exam,'exam_edit_period',$p);examRunNotifications();ck((int)q('SELECT COUNT(*) FROM outbox')->fetchColumn()===$before,'silent edit no mail');ck((int)one('SELECT published FROM exam_windows WHERE id=1')['published']===1,'still published');deny(fn()=>examPost($exam,'exam_edit_period',$p));
$w=one('SELECT * FROM exam_windows WHERE id=1');$p['previous_closes_at']=$w['closes_at'];$p['notify_staff']='1';examPost($exam,'exam_edit_period',$p);ck((int)q('SELECT COUNT(*) FROM outbox WHERE subject=?',[examNoticeSubjects()[3]])->fetchColumn()===1,'optional update only enabled tutor');
$p['closes_at']=$p['opens_at'];deny(fn()=>examPost($exam,'exam_edit_period',$p));
examPost($exam,'exam_staff_preference',['staff_id'=>2,'enabled'=>'1','reason'=>'Returned']);$before=(int)q('SELECT COUNT(*) FROM outbox')->fetchColumn();examRunNotifications();ck(count(examTeachers(true))===2&&(int)q('SELECT COUNT(*) FROM outbox')->fetchColumn()===$before,'enable no replay');
ck((int)q('SELECT COUNT(*) FROM exam_papers WHERE active_key IS NOT NULL')->fetchColumn()===1,'submitted papers retained');
echo "$n exam date/notification control checks passed.\n";
}finally{unlink($path);}
