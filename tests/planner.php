<?php
$path=sys_get_temp_dir().'/planner-'.bin2hex(random_bytes(5)).'.sqlite';putenv('APP_DSN=sqlite:'.$path);require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/import.php';db()->exec(file_get_contents(__DIR__.'/../src/schema.sql'));foreach(['008_academic.sql','009_student_attendance.sql']as $file)db()->exec(file_get_contents(__DIR__.'/../migrations/'.$file));require __DIR__.'/../bin/migrate-attendance-planner.php';
$n=0;function ck($v,$m=''){global $n;if(!$v)throw new Exception('Check '.($n+1).' '.$m);$n++;}function no($f){try{$f();}catch(RuntimeException $e){ck(true);return;}throw new Exception('Expected denial');}
try{
foreach(['faculty','faculty','admin','exam','daa']as $i=>$role){q('INSERT INTO users(name,email,password,role,department) VALUES(?,?,?,?,?)',['Tester '.$i,'test'.$i.'@example.test','unused',$role,departments()[0]]);$users[$i]=one('SELECT * FROM users WHERE id=?',[$i+1]);}
$u=$users[0];$admin=$users[2];$prog='BSc. Agriculture';$dept=examProgrammeDepartment($prog);$p=['kind'=>'attendance','department'=>$dept,'programme'=>$prog,'academic_year'=>'2026-2027','study_year'=>'I','semester'=>'I','section'=>'A','module_code'=>'AG101','module_name'=>'Test','contact_hours'=>20,'classroom'=>'B1'];
$id=acPost($u,'ac_create',$p);ck($id>0,'create outside window');$get=fn()=>spRecord($u,$id);$post=function($who,$action,$extra=[])use($get,$id){return spPost($who,$action,['id'=>$id,'revision'=>$get()['revision']]+$extra);};
no(fn()=>spRecord($users[1],$id));
foreach(['001','002']as $sid)spPost($users[3],'sa_plan_student',['student_id'=>$sid,'name'=>'Student '.$sid,'department'=>$dept,'programme'=>$prog,'academic_year'=>'2026-2027','study_year'=>'I','semester'=>'I','section'=>'A','active'=>'1']);ck(count(spRoster($get()))===2);no(fn()=>spPost($u,'sa_plan_student',[]));
$slot=['weekday'=>'0','starts_on'=>'2026-10-01','ends_on'=>'2026-10-02','period'=>'08:00-09:00','class_type'=>'Practical','classroom'=>'Lab'];$post($u,'sa_plan_slot',$slot);ck(count(all('SELECT * FROM attendance_timetable'))===1);no(fn()=>$post($u,'sa_plan_slot',$slot));
$post($u,'sa_plan_generate',['from'=>'2026-10-01','until'=>'2026-10-02']);$draft=one('SELECT * FROM attendance_drafts');ck(count(acDecode($draft['entries']))===4);ck(spUsed($get(),one('SELECT * FROM attendance_timetable')));no(fn()=>$post($u,'sa_plan_slot_delete',['slot_id'=>1]));
$post($u,'sa_plan_draft_save',['absent'=>'["0|08:00-09:00"]','cell_count'=>'4']);ck(acDecode(one('SELECT entries FROM attendance_drafts')['entries'])[0]['periods']['08:00-09:00']==='A');no(fn()=>$post($u,'sa_plan_submit',['absent'=>'[]','cell_count'=>'4','confirmed'=>'1']));
saPost($users[3],'sa_window_create',['title'=>'Test','academic_year'=>'2026-2027','semester'=>'I','programme'=>$prog,'opens_at'=>date('Y-m-d\TH:i',time()-3600),'closes_at'=>date('Y-m-d\TH:i',time()+3600)]);
$post($u,'sa_plan_submit',['absent'=>'["0|08:00-09:00"]','cell_count'=>'4','confirmed'=>'1']);ck($get()['status']==='Submitted'&&count($get()['entries'])===4);ck(!one('SELECT * FROM attendance_drafts'));no(fn()=>$post($u,'sa_plan_generate',['from'=>'2026-10-01','until'=>'2026-10-02']));
no(fn()=>$post($u,'sa_plan_session_delete',['date'=>'2026-10-01','period'=>'08:00-09:00','reason'=>'Test']));no(fn()=>spPost($users[3],'sa_plan_rights',['user_id'=>1,'can_delete'=>1]));spPost($admin,'sa_plan_rights',['user_id'=>1,'can_edit'=>1,'can_delete'=>1]);ck(spRights($u,$get(),'edit'));$post($u,'sa_plan_session_edit',['date'=>'2026-10-01','period'=>'08:00-09:00','student_id'=>'001','mark'=>'P','reason'=>'Correction']);ck($get()['entries'][0]['periods']['08:00-09:00']==='P');$post($u,'sa_plan_session_delete',['date'=>'2026-10-01','period'=>'08:00-09:00','reason'=>'Mistake','confirmed'=>'1']);ck(count($get()['entries'])===2);
spPost($users[4],'sa_plan_student_delete',['student_id'=>'001']);ck(count($get()['entries'])===2&&count(spRoster($get()))===1);spPost($admin,'sa_plan_rights',['user_id'=>1]);ck(!spRights($u,$get(),'delete'));
no(fn()=>spPost($u,'sa_plan_generate',['id'=>$id,'revision'=>0,'from'=>'2026-10-01','until'=>'2026-10-02']));
no(fn()=>spPost($admin,'sa_plan_period',['label'=>'25:00-26:00','active'=>1]));
ck(examManager($users[4]),'DAA question paper access');
// Existing draft migration is repeatable and preserves every P/A cell.
$legacy=acPost($u,'ac_create',array_merge($p,['module_code'=>'LEG101']));$legacyEntries=$get()['entries'];q('UPDATE academic_records SET entries=? WHERE id=?',[acJson($legacyEntries),$legacy]);require __DIR__.'/../bin/migrate-attendance-planner.php';require __DIR__.'/../bin/migrate-attendance-planner.php';ck(acDecode(one('SELECT entries FROM attendance_drafts WHERE record_id=?',[$legacy])['entries'])===$legacyEntries,'legacy draft retained');ck(acDecode(one('SELECT entries FROM academic_records WHERE id=?',[$legacy])['entries'])===[]);
$examModule=acPost($users[3],'ac_create',array_merge($p,['module_code'=>'EX101']));ck($examModule>0,'Exam Cell module create');
$row=['009','Gender Test','Female',$dept,$prog,'2026-2027','I','I','A','1'];spStudentImport([spStudentHeaders(),$row]);ck(one("SELECT gender FROM attendance_students WHERE student_id='009'")['gender']==='Female');
$headers=spStudentHeaders();array_splice($headers,2,1);array_splice($row,2,1);spStudentImport([$headers,$row]);ck(one("SELECT gender FROM attendance_students WHERE student_id='009'")['gender']==='Female','old template preserves gender');
$row=['010','Gender Test','male',$dept,$prog,'2026-2027','I','I','A','1'];spStudentImport([spStudentHeaders(),$row]);ck(one("SELECT gender FROM attendance_students WHERE student_id='010'")['gender']==='Male');$row[2]='invalid';no(fn()=>spStudentImport([spStudentHeaders(),$row]));
// Weekly form omits dates and class type; special sessions override once only.
$weeklyId=acPost($u,'ac_create',array_merge($p,['module_code'=>'WK101']));
$weeklyPost=function($extra)use($u,$weeklyId){$r=spRecord($u,$weeklyId);return spPost($u,'sa_plan_slot',['id'=>$weeklyId,'revision'=>$r['revision']]+$extra);};
$regular=['schedule_mode'=>'regular','weekday'=>'1','period'=>'08:00-09:00','classroom'=>'B1'];$weeklyPost($regular);$regularSlot=one('SELECT * FROM attendance_timetable WHERE record_id=?',[$weeklyId]);ck($regularSlot['class_type']==='Regular'&&$regularSlot['starts_on']==='2026-01-01');no(fn()=>$weeklyPost($regular));
$special=['schedule_mode'=>'special','weekday'=>'0','starts_on'=>'2026-11-02','ends_on'=>'2026-11-02','period'=>'08:00-09:00','classroom'=>'Lab','class_type'=>'Substitution'];$weeklyPost($special);
$wr=spRecord($u,$weeklyId);$ws=all('SELECT * FROM attendance_timetable WHERE record_id=?',[$weeklyId]);$day=spDaySlots($wr,$ws,'2026-11-02');ck(count($day)===1&&$day[0]['class_type']==='Substitution');$day=spDaySlots($wr,$ws,'2026-11-09');ck(count($day)===1&&$day[0]['class_type']==='Regular');
no(fn()=>$weeklyPost(array_merge($special,['class_type'=>'Extra'])));no(fn()=>$weeklyPost(array_merge($special,['class_type'=>'Practical'])));
foreach(['Practical'=>'2026-11-04','Extra'=>'2026-11-05'] as $type=>$date)$weeklyPost(array_merge($special,['class_type'=>$type,'starts_on'=>$date,'ends_on'=>$date]));
no(fn()=>$weeklyPost(array_merge($special,['class_type'=>'Block Day'])));
$weeklyPost(array_merge($special,['class_type'=>'Block Day','starts_on'=>'2026-10-30','ends_on'=>'2026-10-31','period'=>'']));
$weeklyPost(array_merge($special,['class_type'=>'Block Week','starts_on'=>'2026-11-06','ends_on'=>'2026-11-10','period'=>'']));
$wr=spRecord($u,$weeklyId);$entries=spGenerate($wr,'2026-10-30','2026-11-10');$types=array_unique(array_column($entries,'class_type'));ck(count($types)===5,'Block replaces Monday regular');$result=acAttendance(array_merge($wr,['entries'=>$entries]));ck($result['hours']===45,'12+30 block hours plus 3 normal hours');ck($result['students'][0]['present']===45);
acValidateEntries($wr,$entries,false);$round=saParseMatrix($wr,saMatrix(array_merge($wr,['entries'=>$entries])));ck(acAttendance(array_merge($wr,['entries'=>$round]))['hours']===45,'Export/import preserves six hours');
$entries[0]['periods']['Block (6 hours)']='A';ck(acAttendance(array_merge($wr,['entries'=>$entries]))['students'][0]['absent']===6,'Block absence counts six hours');
// Both block types allow one day and arbitrary multi-day ranges.
foreach(['Block Day','Block Week'] as $i=>$type){
 $date='2026-10-'.(20+$i);$weeklyPost(array_merge($special,['class_type'=>$type,'starts_on'=>$date,'ends_on'=>$date,'period'=>'']));
 $rr=spRecord($u,$weeklyId);$single=spGenerate($rr,$date,$date);ck(acAttendance(array_merge($rr,['entries'=>$single]))['hours']===6,$type.' single day = 6 hours');
}
$weeklyPost(array_merge($special,['class_type'=>'Block Week','starts_on'=>'2026-10-23','ends_on'=>'2026-10-25','period'=>'']));$rr=spRecord($u,$weeklyId);ck(acAttendance(array_merge($rr,['entries'=>spGenerate($rr,'2026-10-23','2026-10-25')]))['hours']===18,'Three-day block week = 18 hours');
ck(spFeedback('sa_plan_student',[])==='Student details saved.');ck(spReturnPage('sa_plan_rights',0,[])==='?page=sa_portal&tools=rights');
$wr=spRecord($u,$weeklyId);spPost($u,'sa_plan_generate',['id'=>$weeklyId,'revision'=>$wr['revision'],'from'=>'2026-11-02','until'=>'2026-11-09']);
no(fn()=>$weeklyPost(array_merge($special,['starts_on'=>'2026-11-09','ends_on'=>'2026-11-09'])));
$wr=spRecord($u,$weeklyId);no(fn()=>spPost($u,'sa_plan_slot_delete',['id'=>$weeklyId,'revision'=>$wr['revision'],'slot_id'=>$regularSlot['id']]));
$beforeDraft=one('SELECT entries FROM attendance_drafts WHERE record_id=?',[$weeklyId])['entries'];
// Used regular entry can be edited with explicit confirmation; draft stays usable.
$weeklyPost(array_merge($regular,['slot_id'=>$regularSlot['id'],'classroom'=>'Updated room','confirm_used_slot'=>'1']));
ck(one('SELECT classroom FROM attendance_timetable WHERE id=?',[$regularSlot['id']])['classroom']==='Updated room');
$wr=spRecord($u,$weeklyId);spPost($u,'sa_plan_slot_delete',['id'=>$weeklyId,'revision'=>$wr['revision'],'slot_id'=>$regularSlot['id'],'confirm_used_slot'=>'1']);
ck(!one('SELECT id FROM attendance_timetable WHERE id=?',[$regularSlot['id']]));
$specialUsed=one("SELECT * FROM attendance_timetable WHERE record_id=? AND class_type='Substitution'",[$weeklyId]);
$weeklyPost(array_merge($specialUsed,['slot_id'=>$specialUsed['id'],'classroom'=>'New lab','confirm_used_slot'=>'1']));
$wr=spRecord($u,$weeklyId);spPost($u,'sa_plan_slot_delete',['id'=>$weeklyId,'revision'=>$wr['revision'],'slot_id'=>$specialUsed['id'],'confirm_used_slot'=>'1']);
$d=one('SELECT * FROM attendance_drafts WHERE record_id=?',[$weeklyId]);ck($d['entries']===$beforeDraft,'Edit/delete retains draft cells');ck((int)$d['revision']===(int)spRecord($u,$weeklyId)['revision'],'Draft revision remains current');
$unused=one("SELECT * FROM attendance_timetable WHERE record_id=? AND starts_on='2026-10-20'",[$weeklyId]);$wr=spRecord($u,$weeklyId);spPost($u,'sa_plan_slot_delete',['id'=>$weeklyId,'revision'=>$wr['revision'],'slot_id'=>$unused['id']]);ck(!one('SELECT id FROM attendance_timetable WHERE id=?',[$unused['id']]),'Unused entry needs no confirmation');
// Submitted history is retained as well.
$history=acDecode($beforeDraft);q('UPDATE academic_records SET entries=? WHERE id=?',[$beforeDraft,$weeklyId]);$usedBlock=one("SELECT * FROM attendance_timetable WHERE record_id=? AND class_type='Block Week' AND starts_on='2026-11-06'",[$weeklyId]);$wr=spRecord($u,$weeklyId);
no(fn()=>spPost($users[1],'sa_plan_slot_delete',['id'=>$weeklyId,'revision'=>$wr['revision'],'slot_id'=>$usedBlock['id'],'confirm_used_slot'=>'1']));
spPost($u,'sa_plan_slot_delete',['id'=>$weeklyId,'revision'=>$wr['revision'],'slot_id'=>$usedBlock['id'],'confirm_used_slot'=>'1']);ck(spRecord($u,$weeklyId)['entries']===$history,'Saved attendance preserved');
// Admin can clear attendance; DAA/Exam Cell require an explicit grant.
$wr=spRecord($u,$weeklyId);no(fn()=>spPost($users[3],'sa_plan_clear',['id'=>$weeklyId,'revision'=>$wr['revision'],'confirmed'=>'1','reason'=>'Test']));
spPost($admin,'sa_plan_rights',['user_id'=>$users[3]['id'],'can_delete'=>'1']);
spPost($users[3],'sa_plan_clear',['id'=>$weeklyId,'revision'=>$wr['revision'],'confirmed'=>'1','reason'=>'Test']);ck(spRecord($u,$weeklyId)['entries']===[],'Granted Exam Cell clears attendance');ck(!one('SELECT record_id FROM attendance_drafts WHERE record_id=?',[$weeklyId]));
spPost($admin,'sa_plan_rights',['user_id'=>$users[3]['id']]);ck(!spDeleteAllowed($users[3],spRecord($u,$weeklyId)),'Revocation works');ck(spDeleteAllowed($admin,spRecord($u,$weeklyId)),'Admin always permitted');
// A past Monday does not prevent a dated block on a different Monday.
$futureId=acPost($u,'ac_create',array_merge($p,['module_code'=>'DATEFIX']));$future=spRecord($u,$futureId);spPost($u,'sa_plan_slot',['id'=>$futureId,'revision'=>$future['revision']]+$regular);
$future=spRecord($u,$futureId);$past=spGenerate($future,'2026-10-05','2026-10-05');q('UPDATE academic_records SET entries=? WHERE id=?',[acJson($past),$futureId]);$future=spRecord($u,$futureId);
spPost($u,'sa_plan_slot',['id'=>$futureId,'revision'=>$future['revision']]+array_merge($special,['class_type'=>'Block Day','starts_on'=>'2026-11-02','ends_on'=>'2026-11-02']));ck(true,'Different-month Monday block accepted');
q('INSERT INTO users(name,email,password,role,department) VALUES(?,?,?,?,?)',['HoD test','hod-test@example.test','unused','hod',$dept]);$hod=one('SELECT * FROM users WHERE email=?',['hod-test@example.test']);no(fn()=>spRecord($hod,$futureId));spPost($admin,'sa_plan_rights',['user_id'=>$hod['id'],'can_delete'=>'1']);ck(spRecord($hod,$futureId)['id']===$futureId,'Granted HoD can view own department');$different=$hod;$different['department']='Other department';no(fn()=>spRecord($different,$futureId));$rr=spRecord($hod,$futureId);no(fn()=>spPost($hod,'sa_plan_generate',['id'=>$futureId,'revision'=>$rr['revision'],'from'=>'2026-11-02','until'=>'2026-11-02']));
$rr=spRecord($u,$futureId);$previous=$rr['entries'];spPost($u,'sa_plan_generate',['id'=>$futureId,'revision'=>$rr['revision'],'from'=>'2026-11-02','until'=>'2026-11-02']);$rr=spRecord($u,$futureId);$next=acDecode(one('SELECT entries FROM attendance_drafts WHERE record_id=?',[$futureId])['entries']);$cells=0;foreach($next as $v)$cells+=count(array_filter($v['periods']));spPost($u,'sa_plan_submit',['id'=>$futureId,'revision'=>$rr['revision'],'confirmed'=>'1','cell_count'=>$cells,'absent'=>'[]']);$saved=spRecord($u,$futureId);ck(count($saved['entries'])===count($previous)+count($next),'Next attendance appends instead of replacing');ck(array_slice($saved['entries'],0,count($previous))===$previous,'Previous attendance retained');
// Complete Admin deletion releases the module identity for recreation.
$rr=spRecord($admin,$futureId);$payload=['id'=>$futureId,'revision'=>$rr['revision'],'confirmed'=>'1'];
no(fn()=>spPost($u,'sa_plan_module_delete',$payload));no(fn()=>spPost($admin,'sa_plan_module_delete',array_merge($payload,['confirmed'=>'0'])));ck((bool)one('SELECT id FROM academic_records WHERE id=?',[$futureId]),'Unconfirmed deletion retains module');
q('INSERT INTO attendance_drafts(record_id,entries,revision,updated_at) VALUES(?,?,?,?)',[$futureId,'[]',$rr['revision'],now()]);
q('INSERT INTO student_attendance_verifications(record_id,student_id,record_revision,decision,document_path,reason,actor_id,created_at) VALUES(?,?,?,?,?,?,?,?)',[$futureId,'001',$rr['revision'],'Approved','test.pdf','test',$admin['id'],now()]);
ck(spPost($admin,'sa_plan_module_delete',$payload)===0,'Returns to module list');foreach(['academic_records'=>'id','attendance_timetable'=>'record_id','attendance_drafts'=>'record_id','student_attendance_verifications'=>'record_id'] as $table=>$key)ck(!one('SELECT * FROM '.$table.' WHERE '.$key.'=?',[$futureId]),'Removed '.$table);
ck((bool)one("SELECT * FROM academic_audit WHERE record_id=? AND action='Complete attendance module deleted'",[$futureId]),'Audit retained');ck((bool)one('SELECT id FROM academic_records WHERE id=?',[$weeklyId]),'Other modules retained');$recreated=acPost($u,'ac_create',array_merge($p,['module_code'=>'DATEFIX']));ck($recreated>0,'Staff can recreate same module');
echo "$n attendance planner checks passed.\n";
}finally{unlink($path);}
