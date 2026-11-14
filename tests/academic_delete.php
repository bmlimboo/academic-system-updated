<?php
require __DIR__.'/../src/bootstrap.php';$n=0;
function ck($v,$m){global $n;if(!$v)throw new RuntimeException($m);$n++;}
function denied(callable $f,string $message){try{$f();throw new RuntimeException('Expected rejection');}catch(RuntimeException $e){ck(str_contains($e->getMessage(),$message),$e->getMessage());}}
$admin=one("SELECT * FROM users WHERE role='admin'");
function fixture(string $kind,string $code,array $metadata=[]):int{global $admin;q("INSERT INTO academic_records(owner_id,kind,identity_key,department,programme,academic_year,study_year,semester,section,module_code,module_name,contact_hours,metadata,entries,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",[$admin['id'],$kind,hash('sha256',$code),'Department of Agriculture','BSc. Agriculture','2026-2027','I','I','A',$code,'Dummy',60,json_encode($metadata),'[]','Submitted',now(),now()]);return (int)db()->lastInsertId();}
$id=fixture('assessment','DUMMY');$p=['id'=>$id,'revision'=>1,'confirmed'=>'1','reason'=>'Dummy test'];
foreach(['faculty','pl','daa','exam']as $role){$u=$admin;$u['role']=$role;denied(fn()=>acPost($u,'ac_admin_delete',$p),'Only Admin');}
denied(fn()=>acPost($admin,'ac_admin_delete',array_merge($p,['confirmed'=>'0'])),'Confirm deletion');
denied(fn()=>acPost($admin,'ac_admin_delete',array_merge($p,['reason'=>''])),'Please complete reason');
denied(fn()=>acPost($admin,'ac_admin_delete',array_merge($p,['revision'=>99])),'changed');
$ra=fixture('reassessment','RA',['original_id'=>$id]);denied(fn()=>acPost($admin,'ac_admin_delete',$p),'linked re-assessment');ck((bool)one('SELECT id FROM academic_records WHERE id=?',[$id]),'original preserved');
ck(acPost($admin,'ac_admin_delete',array_merge($p,['id'=>$ra]))===0,'delete RA');ck(acPost($admin,'ac_admin_delete',$p)===0,'delete original');ck(!one('SELECT id FROM academic_records WHERE id=?',[$id]),'record removed');$log=one("SELECT details FROM academic_audit WHERE record_id=? AND action='Administrator deleted academic record'",[$id]);ck(acDecode($log['details'])['record']['module_code']==='DUMMY','snapshot retained');ck(!one('SELECT id FROM academic_records WHERE identity_key=?',[hash('sha256','DUMMY')]),'identity freed');
$id=fixture('attendance','ATT');q('INSERT INTO student_attendance_verifications(record_id,student_id,record_revision,decision,document_path,reason,actor_id,created_at) VALUES(?,?,?,?,?,?,?,?)',[$id,'ST1',1,'Approved','test.pdf','Test',$admin['id'],now()]);acPost($admin,'ac_admin_delete',array_merge($p,['id'=>$id]));ck(!one('SELECT id FROM student_attendance_verifications WHERE record_id=?',[$id]),'verification removed from active data');$log=one("SELECT details FROM academic_audit WHERE record_id=? AND action='Administrator deleted academic record'",[$id]);ck(count(acDecode($log['details'])['verifications'])===1,'verification snapshot retained');
echo "$n academic deletion checks passed.\n";
