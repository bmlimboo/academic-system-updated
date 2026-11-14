<?php
$path=sys_get_temp_dir().'/cnr-route-reset-'.bin2hex(random_bytes(5)).'.sqlite';putenv('APP_DSN=sqlite:'.$path);putenv('APP_DEMO=0');
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/actions.php';
db()->exec(file_get_contents(__DIR__.'/../src/schema.sql'));db()->exec(file_get_contents(__DIR__.'/../migrations/006_password_reset.sql'));$n=0;
function ck($ok,$msg){global $n;if(!$ok)throw new RuntimeException($msg);$n++;}
function denied($fn,$msg){try{$fn();}catch(RuntimeException $e){ck(true,$msg);return;}throw new RuntimeException('Not denied: '.$msg);}
foreach(['faculty','hod','daa','president','admin']as $role)q('INSERT INTO users(name,email,password,role,department) VALUES(?,?,?,?,?)',[$role,$role.'@example.test',password_hash('OriginalPassword123',PASSWORD_DEFAULT),$role,departments()[0]]);
$u=[];foreach(all('SELECT * FROM users')as $v)$u[$v['role']]=$v;
$p=['module'=>'substitution','department'=>departments()[0],'no_substitution_reason'=>'No classes scheduled.','substitution_required'=>'No','travel_start'=>'2026-10-12','travel_end'=>'2026-10-12','return_date'=>'2026-10-12','purpose_category'=>'Training','place'=>'Thimphu','funding_agency'=>'College','transportation'=>'Pool'];
foreach(['faculty'=>'Pending HoD/PL','hod'=>'Pending DAA','daa'=>'Pending President']as $role=>$state){
 $id=submitRequest($u[$role],$p);$r=one('SELECT * FROM requests WHERE id=?',[$id]);ck($r['status']===$state,'initial '.$role);ck(!substitutionActions($u[$role],$r),'no self '.$role);
 $mail=one('SELECT body FROM outbox WHERE subject LIKE ?',['Staff Substitution #'.$id.'%']);ck(str_contains($mail['body'],'https://cas.cnr.edu.bt/?page=detail'),'domain '.$role);
 if($role==='faculty'){denied(fn()=>transition($u['daa'],$id,'Approved',''),'DAA cannot skip HoD');transition($u['hod'],$id,'Pending DAA','Reviewed');transition($u['daa'],$id,'Approved','Reviewed');}
 if($role==='hod'){ck(approvalStages($r)['HoD']['status']==='Not applicable','HoD exempt');denied(fn()=>transition($u['president'],$id,'Approved',''),'President cannot approve HoD');transition($u['daa'],$id,'Approved','Reviewed');}
 if($role==='daa'){ck(canView($u['president'],$r),'President sees DAA');ck(approvalStages($r)['DAA']['status']==='Not applicable','DAA exempt');denied(fn()=>transition($u['hod'],$id,'Pending DAA',''),'HoD cannot act on DAA');denied(fn()=>transition($u['daa'],$id,'Approved',''),'DAA self blocked');denied(fn()=>transition($u['president'],$id,'Rejected by President',''),'President rejection reason');transition($u['president'],$id,'Approved','Reviewed');}
 $r=one('SELECT * FROM requests WHERE id=?',[$id]);$stage=$role==='daa'?'President':'DAA';ck(approvalStages($r)[$stage]['status']==='Approved','final stage '.$role);ck(approvalStages($r)[$stage]['name']===strtolower($stage),'approver attribution '.$role);denied(fn()=>transition($u['president'],$id,'Approved',''),'no repeated approval');
}
$id=submitRequest($u['daa'],$p);transition($u['president'],$id,'Rejected by President','Please clarify arrangements.');ck(approvalStages(one('SELECT * FROM requests WHERE id=?',[$id]))['President']['status']==='Rejected','President rejection recorded');
function latestToken(){ $m=one("SELECT body FROM outbox WHERE subject='CNR Academic Services - Reset your password' ORDER BY id DESC LIMIT 1");preg_match('/token=([a-f0-9]{64})/',$m['body'],$matches);return $matches[1];}
requestPasswordReset('HOD@example.test','1.2.3.4');$t=latestToken();ck((bool)one('SELECT * FROM password_reset_tokens WHERE token_hash=?',[hash('sha256',$t)]),'hashed token stored');
requestPasswordReset('hod@example.test','1.2.3.4');$new=latestToken();denied(fn()=>resetOfficialPassword($t,'ReplacementPassword123','ReplacementPassword123'),'older token revoked');
denied(fn()=>resetOfficialPassword($new,'short','short'),'short password');denied(fn()=>resetOfficialPassword($new,'ReplacementPassword123','wrong'),'mismatch');
resetOfficialPassword($new,'ReplacementPassword123','ReplacementPassword123');ck(password_verify('ReplacementPassword123',one('SELECT password FROM users WHERE id=?',[$u['hod']['id']])['password']),'password changed');denied(fn()=>resetOfficialPassword($new,'AnotherPassword123','AnotherPassword123'),'replay');
requestPasswordReset('daa@example.test','1.2.3.4');$t=latestToken();q('UPDATE password_reset_tokens SET expires_at=? WHERE token_hash=?',[time()-1,hash('sha256',$t)]);denied(fn()=>resetOfficialPassword($t,'ReplacementPassword123','ReplacementPassword123'),'expiry');
requestPasswordReset('president@example.test','1.2.3.4');$t=latestToken();q('UPDATE users SET active=0 WHERE id=?',[$u['president']['id']]);denied(fn()=>resetOfficialPassword($t,'ReplacementPassword123','ReplacementPassword123'),'disabled official');
$before=q('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn();foreach(['faculty@example.test','absent@example.test','president@example.test']as $email)requestPasswordReset($email,'2.3.4.5');ck(q('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn()===$before,'no reset for faculty unknown inactive');
for($i=0;$i<5;$i++)requestPasswordReset('admin@example.test','3.4.5.6');ck(q('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id=?',[$u['admin']['id']])->fetchColumn()==3,'email rate limit');
// Backfill changes only unresolved requests and is repeatable.
$pending=[];foreach(['hod','daa'] as $role){$id=submitRequest($u[$role],$p);$r=one('SELECT * FROM requests WHERE id=?',[$id]);$payload=json_decode($r['payload'],true);unset($payload['approval_route']);q('UPDATE requests SET status=?,payload=? WHERE id=?',['Pending HoD/PL',json_encode($payload),$id]);$pending[$role]=$id;}
$before=all("SELECT id,status,payload FROM requests WHERE status IN ('Approved','Rejected by President') ORDER BY id");
$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../bin/update-approval-routes.php');exec($command,$output,$code);ck($code===0,'migration preview runs');ck(one('SELECT status FROM requests WHERE id=?',[$pending['hod']])['status']==='Pending HoD/PL','preview read only');
exec($command.' --apply',$output,$code);ck($code===0,'migration applied');ck(one('SELECT status FROM requests WHERE id=?',[$pending['hod']])['status']==='Pending DAA','pending HoD repaired');ck(one('SELECT status FROM requests WHERE id=?',[$pending['daa']])['status']==='Pending President','pending DAA repaired');ck($before===all("SELECT id,status,payload FROM requests WHERE status IN ('Approved','Rejected by President') ORDER BY id"),'completed records preserved');
$emails=q('SELECT COUNT(*) FROM outbox')->fetchColumn();exec($command.' --apply',$output,$code);ck($code===0&&$emails===q('SELECT COUNT(*) FROM outbox')->fetchColumn(),'migration idempotent');
ck((int)q('SELECT MAX(LENGTH(identity)) FROM login_attempts')->fetchColumn()<=64,'rate limit identifiers fit MySQL schema');
unlink($path);echo "$n approval and password reset checks passed.\n";
