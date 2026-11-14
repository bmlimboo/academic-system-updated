<?php
require_once __DIR__.'/planner-feedback.php';
require_once __DIR__.'/planner-access.php';
require_once __DIR__.'/planner-schedule.php';
function spReady():bool{try{q('SELECT record_id FROM attendance_drafts LIMIT 1');return true;}catch(Throwable $e){return false;}}
function spRecord(array $u,int $id,bool $lock=false):array{
 $r=one("SELECT * FROM academic_records WHERE kind='attendance' AND id=?".($lock&&db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[$id]);
 acAssert($r&&((spBrowse($u)&&spScope($u,$r))||(acTutor($u)&&(int)$r['owner_id']===(int)$u['id'])),'Attendance module is not available to your account.');return acHydrate($r);
}
function spWindow(array $r):?array{return one("SELECT * FROM student_attendance_windows WHERE enabled=1 AND academic_year=? AND semester=? AND (programme='' OR programme=?) AND opens_at<=? AND closes_at>? ORDER BY closes_at DESC,id DESC",[$r['academic_year'],$r['semester'],$r['programme'],now(),now()]) ?: null;}
function spRights(array $u,array $r,string $right):bool{if($right==='delete')return spDeleteAllowed($u,$r);return saReviewer($u)||((int)$r['owner_id']===(int)$u['id']&&(bool)(one('SELECT * FROM attendance_rights WHERE user_id=?',[$u['id']])['can_'.$right]??false));}
function spRoster(array $r):array{return all('SELECT * FROM attendance_students WHERE active=1 AND programme=? AND academic_year=? AND study_year=? AND semester=? AND section=? ORDER BY name,student_id',[$r['programme'],$r['academic_year'],$r['study_year'],$r['semester'],$r['section']]);}
function spGenders():array{return ['Male','Female','Other','Prefer not to say'];}
function spStudentHeaders():array{return ['Student ID','Student Name','Gender','Department','Programme','Academic Year','Year','Semester','Section','Active'];}
function spStudentImport(array $rows):int{
 $headers=array_map('trim',$rows[0]??[]);$current=spStudentHeaders();$legacy=$current;array_splice($legacy,2,1);
 acAssert($headers===$current||$headers===$legacy,'Use the student register template headers.');
 $keys=['student_id','name','gender','department','programme','academic_year','study_year','semester','section','active'];if($headers===$legacy)array_splice($keys,2,1);
 $seen=[];foreach(array_slice($rows,1)as $rowIndex=>$row){if(!array_filter($row))continue;try{acAssert(count($row)===count($headers),'Each student row must match the template columns.');$input=array_combine($keys,array_map('strval',$row));
 if($headers===$legacy)$input['gender']=one('SELECT gender FROM attendance_students WHERE student_id=?',[trim($input['student_id'])])['gender']??'';
 $v=spStudent($input);acAssert(!isset($seen[$v['student_id']]),'Duplicate student ID in upload.');$seen[$v['student_id']]=$rowIndex+2;spSaveStudent($v);}catch(RuntimeException $e){throw new RuntimeException('Excel/CSV row '.($rowIndex+2).' (Student ID: '.trim((string)($row[0]??'blank')).'): '.$e->getMessage());}}
 acAssert((bool)$seen,'No students found.');return count($seen);
}
function spStudent(array $p):array{
 $v=[];foreach(['student_id','name','department','programme','academic_year','study_year','semester','section'] as $key)$v[$key]=acText($p,$key,$key==='student_id'?60:190);
 $expected=examProgrammeDepartment($v['programme']);acAssert($expected===$v['department'],'Department: entered “'.$v['department'].'”; expected “'.$expected.'” for Programme “'.$v['programme'].'”.');acAssert(preg_match('/^(20\d\d)-(20\d\d)$/D',$v['academic_year'],$years)===1&&(int)$years[2]===(int)$years[1]+1,'Use academic year YYYY-YYYY.');acAssert(in_array($v['study_year'],['I','II','III','IV'],true)&&in_array($v['semester'],['I','II'],true),'Use roman numerals for year and semester.');$v['active']=($p['active']??'1')==='0'?0:1;$gender=trim((string)($p['gender']??''));$v['gender']='';foreach(spGenders() as $option)if(strcasecmp($gender,$option)===0)$v['gender']=$option;acAssert($gender===''||$v['gender']!=='','Gender must be Male, Female, Other or Prefer not to say (or blank).');return $v;
}
function spSaveStudent(array $v):void{
 $values=[$v['name'],$v['department'],$v['programme'],$v['academic_year'],$v['study_year'],$v['semester'],$v['section'],$v['active'],$v['gender'],$v['student_id']];
 if(one('SELECT student_id FROM attendance_students WHERE student_id=?',[$v['student_id']]))q('UPDATE attendance_students SET name=?,department=?,programme=?,academic_year=?,study_year=?,semester=?,section=?,active=?,gender=? WHERE student_id=?',$values);
 else q('INSERT INTO attendance_students(name,department,programme,academic_year,study_year,semester,section,active,gender,student_id) VALUES(?,?,?,?,?,?,?,?,?,?)',$values);
}
function spUsed(array $r,array $slot):bool{
 $slot=spSlotBounds($r,$slot);
 $entries=$r['entries'];$d=one('SELECT entries FROM attendance_drafts WHERE record_id=?',[$r['id']]);if($d)$entries=array_merge($entries,acDecode($d['entries']));
 foreach($entries as $v)if($v['date']>=$slot['starts_on']&&$v['date']<=$slot['ends_on']&&((int)$slot['weekday']===0||(int)date('N',strtotime($v['date']))===(int)$slot['weekday'])&&(spBlock($slot)?(bool)array_filter($v['periods']):(($v['periods'][$slot['period']]??'')!==''||!empty($v['periods']['Block (6 hours)']))))return true;return false;
}
function spGenerate(array $r,string $from,string $until):array{
 $from=acDate($from);$until=acDate($until);acAssert($until>=$from&&$until<=date('Y-m-d'),'Select dates in order, no later than today.');acAssert((strtotime($until)-strtotime($from))/86400<=92,'Generate no more than 93 days at once.');
 $students=spRoster($r);acAssert((bool)$students,'No active students match this programme, academic year, year, semester and section. Ask Exam Cell to import students.');
 $slots=all('SELECT * FROM attendance_timetable WHERE record_id=? ORDER BY period',[$r['id']]);$occupied=[];foreach($r['entries'] as $v)foreach($v['periods'] as $p=>$value)if($value!=='')$occupied[$v['date'].'|'.$p]=true;
 $out=[];$cells=0;
 for($day=$from;$day<=$until;$day=date('Y-m-d',strtotime($day.' +1 day')))foreach(spDaySlots($r,$slots,$day) as $slot){
  if(isset($occupied[$day.'|'.$slot['period']]))continue;
  [$start,$end]=spTimes($slot['period']);foreach($r['entries'] as $existing)if($existing['date']===$day)foreach($existing['periods'] as $period=>$mark)if($mark!==''){[$a,$b]=spTimes($period);acAssert($start>=$b||$end<=$a,'This timetable overlaps recorded attendance on '.$day.' at '.$period.'. Correct the timetable or recorded session first.');}
  foreach($students as $student){$key=$student['student_id'].'|'.$day.'|'.$slot['class_type'];$out[$key]??=['student_id'=>$student['student_id'],'student_name'=>$student['name'],'date'=>$day,'classroom'=>$slot['classroom'],'class_type'=>$slot['class_type'],'periods'=>array_fill_keys(acPeriods(),'')];acAssert($out[$key]['classroom']===$slot['classroom'],'Use one classroom per student/date/class type.');$out[$key]['periods'][$slot['period']]='P';$cells++;acAssert($cells<=10000,'Too many attendance cells; choose a shorter date range.');}
 }
 acAssert((bool)$out,'No new timetable sessions in this range. Already recorded sessions are skipped.');return array_values($out);
}
function spPost(array $u,string $action,array $p):int{
 acAssert((acTutor($u)||saReviewer($u))&&spReady(),'Sign in to use attendance timetables.');db()->beginTransaction();try{
 $id=(int)($p['id']??0);
 if(in_array($action,['sa_plan_student','sa_plan_student_delete','sa_plan_import','sa_plan_period','sa_plan_rights'],true)){
  acAssert(saReviewer($u),'Only Admin, Exam Cell and DAA can manage students and period times.');
  if($action==='sa_plan_rights'){acAssert(acAdmin($u),'Only Admin can grant tutor edit/delete rights.');$uid=(int)($p['user_id']??0);$target=one('SELECT * FROM users WHERE id=?',[$uid]);acAssert($target&&!empty($target['active'])&&(acTutor($target)||in_array($target['role'],['daa','exam','hod','pl'],true)),'Choose an active tutor.');$values=[isset($p['can_edit'])?1:0,isset($p['can_delete'])?1:0,$uid];if(one('SELECT user_id FROM attendance_rights WHERE user_id=?',[$uid]))q('UPDATE attendance_rights SET can_edit=?,can_delete=? WHERE user_id=?',$values);else q('INSERT INTO attendance_rights(can_edit,can_delete,user_id) VALUES(?,?,?)',$values);}
  elseif($action==='sa_plan_period'){$label=acText($p,'label',20);acAssert(preg_match('/^((?:[01]\d|2[0-3]):[0-5]\d)-((?:[01]\d|2[0-3]):[0-5]\d)$/D',$label,$m)===1&&strtotime($m[2])-strtotime($m[1])===3600,'Use a one-hour period HH:MM-HH:MM; attendance currently counts one hour per period.');$old=trim($p['old_label']??'');if($old!==''&&$old!==$label){acAssert(!one('SELECT id FROM attendance_timetable WHERE period=?',[$old]),'This period is used by a timetable. Add a new period instead.');foreach(all("SELECT entries FROM academic_records WHERE kind='attendance'") as $x)foreach(acDecode($x['entries']) as $v)acAssert(empty($v['periods'][$old]),'This period has attendance. Add a new period instead.');q('DELETE FROM attendance_periods WHERE label=?',[$old]);}if(one('SELECT label FROM attendance_periods WHERE label=?',[$label]))q('UPDATE attendance_periods SET active=? WHERE label=?',[isset($p['active'])?1:0,$label]);else q('INSERT INTO attendance_periods(label,active) VALUES(?,1)',[$label]);}
  elseif($action==='sa_plan_student'){spSaveStudent(spStudent($p));}
  elseif($action==='sa_plan_student_delete'){$sid=acText($p,'student_id',60);$before=one('SELECT * FROM attendance_students WHERE student_id=?',[$sid]);q('DELETE FROM attendance_students WHERE student_id=?',[$sid]);acAudit($u,0,'Student removed from roster; attendance retained',['student'=>$before]);}
  else{spStudentImport(saReadFile($_FILES['sheet']??[]));}
  acAudit($u,0,'Attendance register/settings updated',['action'=>$action]);$id=0;
 }else{
 $r=spRecord($u,$id,true);if(!saReviewer($u)&&(int)$r['owner_id']!==(int)$u['id'])acAssert(in_array($action,['sa_plan_clear','sa_plan_session_delete'],true),'Delegated access permits viewing and deleting attendance only.');acAssert((int)($p['revision']??-1)===(int)$r['revision'],'Module changed. Reload before saving.');
 if(in_array($action,['sa_plan_slot','sa_plan_slot_delete'],true)){
  $sid=(int)($p['slot_id']??0);$old=$sid?one('SELECT * FROM attendance_timetable WHERE id=? AND record_id=?',[$sid,$id]):null;acAssert(!$sid||$old,'Timetable slot not found.');if($old&&spUsed($r,$old))acAssert(($p['confirm_used_slot']??'')==='1','Attendance already exists for this timetable entry. Confirm that you want to change or delete the timetable only; saved attendance and drafts will be retained.');
  if($action==='sa_plan_slot_delete'){acAssert((bool)$old,'Choose a slot.');q('DELETE FROM attendance_timetable WHERE id=?',[$sid]);}
  else{if(($p['schedule_mode']??'')==='regular'){[$first,$last]=explode('-',$r['academic_year']);$p['starts_on']=$first.'-01-01';$p['ends_on']=$last.'-12-31';$p['class_type']='Regular';acAssert((int)($p['weekday']??0)>=1&&(int)$p['weekday']<=7,'Choose a weekday for regular classes.');}elseif(($p['schedule_mode']??'')==='special'){acAssert(in_array($p['class_type']??'', ['Block Day','Block Week','Substitution','Practical','Extra'],true),'Choose a special class type.');}$from=acDate(acText($p,'starts_on',10));$end=acDate(acText($p,'ends_on',10));acAssert($end>=$from,'End date must follow start date.');[$ya,$yb]=explode('-',$r['academic_year']);acAssert($from>=$ya.'-01-01'&&$end<=$yb.'-12-31','Timetable dates must be within the academic year.');$isBlock=in_array($p['class_type']??'',['Block Day','Block Week'],true);if($isBlock){$p['weekday']=0;$p['period']='Block (6 hours)';}$weekday=(int)($p['weekday']??-1);acAssert($weekday>=0&&$weekday<=7,'Choose weekday or date-specific sessions.');$period=acText($p,'period',20);acAssert($isBlock||(bool)one('SELECT label FROM attendance_periods WHERE label=? AND active=1',[$period]),'Choose an enabled period.');$type=acText($p,'class_type',30);acAssert(in_array($type,acClassTypes(),true),'Invalid class type.');$room=acText($p,'classroom',100);
   spValidateSlot($r,['weekday'=>$weekday,'starts_on'=>$from,'ends_on'=>$end,'period'=>$period,'class_type'=>$type,'classroom'=>$room],$sid,$old&&($p['confirm_used_slot']??'')==='1');
   if($old)q('UPDATE attendance_timetable SET weekday=?,starts_on=?,ends_on=?,period=?,class_type=?,classroom=? WHERE id=?',[$weekday,$from,$end,$period,$type,$room,$sid]);else q('INSERT INTO attendance_timetable(record_id,weekday,starts_on,ends_on,period,class_type,classroom) VALUES(?,?,?,?,?,?,?)',[$id,$weekday,$from,$end,$period,$type,$room]);
  }
 $savedSlotId=$sid?: (int)db()->lastInsertId();
 // Timetable changes do not alter historical attendance or an existing checklist.
 q('UPDATE attendance_drafts SET revision=? WHERE record_id=?',[$r['revision']+1,$id]);
 acAudit($u,$id,'Timetable entry '.($action==='sa_plan_slot_delete'?'deleted':'saved'),['before'=>$old,'after'=>$action==='sa_plan_slot_delete'?null:one('SELECT * FROM attendance_timetable WHERE id=?',[$savedSlotId]),'attendance_retained'=>true]);
 }elseif($action==='sa_plan_module_delete'){
  acAssert(acAdmin($u),'Only Admin can delete the complete attendance module.');acAssert(($p['confirmed']??'')==='1','Tick the confirmation before deleting this module.');
  acAudit($u,$id,'Complete attendance module deleted',['record'=>$r,'timetable'=>all('SELECT * FROM attendance_timetable WHERE record_id=?',[$id]),'draft'=>one('SELECT * FROM attendance_drafts WHERE record_id=?',[$id]),'verifications'=>all('SELECT * FROM student_attendance_verifications WHERE record_id=?',[$id]),'confirmed'=>true]);
  q('DELETE FROM attendance_drafts WHERE record_id=?',[$id]);q('DELETE FROM attendance_timetable WHERE record_id=?',[$id]);q('DELETE FROM student_attendance_verifications WHERE record_id=?',[$id]);q('DELETE FROM academic_records WHERE id=?',[$id]);db()->commit();return 0;
 }elseif($action==='sa_plan_clear'){
  acAssert(spDeleteAllowed($u,$r),'Admin must grant attendance deletion permission.');acAssert(($p['confirmed']??'')==='1','Confirm deletion of recorded attendance.');$reason='Confirmed deletion';acAudit($u,$id,'Recorded attendance deleted',['before'=>$r['entries'],'draft'=>one('SELECT * FROM attendance_drafts WHERE record_id=?',[$id]),'reason'=>$reason]);q("UPDATE academic_records SET entries='[]',status='Draft',submitted_at=NULL WHERE id=?",[$id]);q('DELETE FROM attendance_drafts WHERE record_id=?',[$id]);
 }elseif($action==='sa_plan_generate'){
  acAssert(!one('SELECT record_id FROM attendance_drafts WHERE record_id=?',[$id]),'Continue or discard the existing attendance draft first.');$entries=spGenerate($r,acText($p,'from',10),acText($p,'until',10));q('INSERT INTO attendance_drafts(record_id,entries,revision,updated_at) VALUES(?,?,?,?)',[$id,acJson($entries),$r['revision']+1,now()]);
 }elseif($action==='sa_plan_draft_discard'){q('DELETE FROM attendance_drafts WHERE record_id=?',[$id]);}
 elseif(in_array($action,['sa_plan_draft_save','sa_plan_submit'],true)){
  $draft=one('SELECT * FROM attendance_drafts WHERE record_id=?',[$id]);acAssert($draft&&(int)$draft['revision']===(int)$r['revision'],'Draft is stale. Discard and generate it again.');$entries=acDecode($draft['entries']);$absent=acDecode((string)($p['absent']??'[]'));$cells=0;foreach($entries as $i=>&$v)foreach($v['periods'] as $period=>&$mark)if($mark!==''){$mark=in_array($i.'|'.$period,$absent,true)?'A':'P';$cells++;}unset($v,$mark);acAssert((int)($p['cell_count']??-1)===$cells,'Attendance form incomplete; reload and try again.');
  if($action==='sa_plan_submit'){acAssert(($p['confirmed']??'')==='1','Confirm attendance before submitting.');acAssert(saReviewer($u)||(bool)spWindow($r),'Attendance submission is closed for this module. Draft retained.');$merged=saMerge($r,$r['entries'],$entries,true);acValidateEntries($r,$merged,false);q("UPDATE academic_records SET entries=?,status='Submitted',submitted_at=?,updated_at=? WHERE id=?",[acJson($merged),now(),now(),$id]);q('DELETE FROM attendance_drafts WHERE record_id=?',[$id]);acAudit($u,$id,'Timetable attendance submitted',['before'=>$r['entries'],'added'=>$entries]);}
  else q('UPDATE attendance_drafts SET entries=?,revision=?,updated_at=? WHERE record_id=?',[acJson($entries),$r['revision']+1,now(),$id]);
 }elseif(in_array($action,['sa_plan_session_delete','sa_plan_session_edit'],true)){
  $right=$action==='sa_plan_session_delete'?'delete':'edit';acAssert(spRights($u,$r,$right),'Admin has not granted attendance '.$right.' permission.');$date=acDate(acText($p,'date',10));$period=acText($p,'period',20);$before=$r['entries'];$found=false;$sid=trim($p['student_id']??'');$mark=$p['mark']??'';
  foreach($r['entries'] as &$v)if($v['date']===$date&&($v['periods'][$period]??'')!==''&&($right==='delete'||$v['student_id']===$sid)){$found=true;if($right==='delete')$v['periods'][$period]='';else{acAssert(in_array($mark,['P','A'],true),'Select Present or Absent.');$v['periods'][$period]=$mark;}}unset($v);acAssert($found,'Attendance session or student not found.');$entries=array_values(array_filter($r['entries'],fn($v)=>(bool)array_filter($v['periods'])));$reason=$right==='delete'?'Confirmed deletion':acText($p,'reason',1000);if($right==='delete')acAssert(($p['confirmed']??'')==='1','Confirm deletion of this attendance session.');q('UPDATE academic_records SET entries=? WHERE id=?',[acJson($entries),$id]);acAudit($u,$id,'Attendance session '.$right,['before'=>$before,'after'=>$entries,'reason'=>$reason]);
 }else throw new RuntimeException('Unknown timetable action.');
 q('UPDATE academic_records SET revision=revision+1,updated_at=? WHERE id=?',[now(),$id]);acAudit($u,$id,'Attendance planner updated',['action'=>$action]);
 }
 db()->commit();return $id;
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
