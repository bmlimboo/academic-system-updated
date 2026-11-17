<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Thimphu');
$config = require __DIR__.'/../config/config.php';
if(!$config['demo'])$config['url']='https://cas.cnr.edu.bt';
require_once __DIR__.'/password-reset.php';
require_once __DIR__.'/substitution-form.php';
require_once __DIR__.'/request-admin.php';
require_once __DIR__.'/office-order.php';
require_once __DIR__.'/exam-portal.php';
require_once __DIR__.'/exam-programmes.php';
require_once __DIR__.'/academic/core.php';
require_once __DIR__.'/college-calendar/core.php';
require_once __DIR__.'/academic/download.php';
require_once __DIR__.'/academic/student-attendance.php';
require_once __DIR__.'/academic/attendance-matrix.php';
require_once __DIR__.'/academic/attendance-bulk.php';
if(is_file(__DIR__.'/../vendor/autoload.php'))require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/attendance/core.php';
require_once __DIR__.'/attendance/calendar.php';
require_once __DIR__.'/attendance/import.php';
require_once __DIR__.'/attendance/report.php';
require_once __DIR__.'/attendance/controller.php';
require_once __DIR__.'/attendance/enrolment.php';
require_once __DIR__.'/sample-timetables.php';
require_once __DIR__.'/consultation.php';
require_once __DIR__.'/library.php';
require_once __DIR__.'/library-delivery.php';
require_once __DIR__.'/research/core.php';
require_once __DIR__.'/navigation.php';
require_once __DIR__.'/staff-management.php';
function db(): PDO { global $config; static $db; if (!$db) {$db=new PDO($config['dsn'],$config['db_user'],$config['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); if(str_starts_with($config['dsn'],'sqlite:')) $db->exec('PRAGMA busy_timeout=5000');} return $db; }
function q(string $sql,array $params=[]): PDOStatement {$s=db()->prepare($sql);$s->execute($params);return $s;}
function all(string $sql,array $params=[]):array{return q($sql,$params)->fetchAll();}
function one(string $sql,array $params=[]):array|false{return q($sql,$params)->fetch();}
function e(mixed $v):string{return htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8');}
function now():string{return date('Y-m-d H:i:s');}
function roles(array $u,array $roles):bool{return in_array($u['role'],$roles,true);}
function staff():array{return all("SELECT id,name,email,role,department,active FROM users WHERE active=1 AND role <> 'student' ORDER BY name");}
function programmes():array{return ['BSc. Agriculture','BSc. Animal Science','BSc. Sustainable Development','BSc. Environment and Climate Studies','BSc. Food Science and Technology','BSc. Forestry','PhD in Climate Studies','MSc. Conservation Biology','MSc. Natural Resources Management','Diploma in Agriculture','Diploma in Animal Science','MA Development Studies','MSc. Sustainable Mountain Agriculture'];}
function accountDepartments():array{return array_merge(departments(),['Exam Cell','Adm Section','Science Laboratory']);}
function departments():array{return ['Department of Agriculture','Department of Animal Science','Department of Food Science & Technology','Department of Forest Science','Department of Environment and Climate Studies','Department of Sustainable Development'];}
function modules():array{return ['substitution'=>'Staff Substitution','consultation'=>'Student Consultation','exam'=>'Exam Cell Requisition','library'=>'Library Services'];}
function canView(array $u,array $r):bool {if(($r['status']??'')==='Deleted')return ($u['role']??'')==='admin';if(($u['role']??'guest')==='guest')return false;if(roles($u,['admin','daa']))return true;if((int)$r['applicant_id']===(int)$u['id'])return true;if($r['module']==='substitution'){if($u['role']==='president'&&requestApprovalRoute($r)==='daa_president')return true;if(roles($u,['hod','pl'])&&$u['department']===$r['department'])return true;if($r['status']==='Approved'&&roles($u,['president','administration']))return true;return (bool)one('SELECT id FROM sessions WHERE request_id=? AND faculty_id=?',[$r['id'],$u['id']]);}return ($r['module']==='exam'&&roles($u,['admin','exam']))||($r['module']==='library'&&roles($u,['admin','librarian']))||($r['module']==='consultation'&&((int)$r['receiver_id']===(int)$u['id']||roles($u,['admin','daa'])));}
function canSubmit(array $u,string $module):bool {return ($u['role']??'guest')==='guest'||(!empty($u['active'])&&($module==='consultation'||$u['role']!=='student'));}
function canDocuments(array $u):bool{return roles($u,['admin','daa','pl','librarian']);}
function audit(int $id,int $actor,string $action,string $note=''):void{q('INSERT INTO history(request_id,actor_id,action,note,created_at) VALUES(?,?,?,?,?)',[$id,$actor,$action,$note,now()]);}
function notifyRequest(int $id,string $event):void {
 global $config;$r=one('SELECT r.*,u.email,u.name FROM requests r JOIN users u ON u.id=r.applicant_id WHERE r.id=?',[$id]);$emails=[$r['email']];if($r['status']==='Deleted')return;
 $addRole=function(string $role,?string $department=null)use(&$emails,$r){if($role==='daa'&&$r['module']==='substitution'){$emails[]='daa.cnr@rub.edu.bt';return;}foreach(all('SELECT email FROM users WHERE active=1 AND role=?'.($department!==null?' AND department=?':''),$department!==null?[$role,$department]:[$role])as $u)$emails[]=$u['email'];};
 if($r['module']==='substitution'){
  $route=requestApprovalRoute($r);
  if($route==='daa_president')$addRole('president');else{$addRole('daa');if($route==='faculty_hod_daa')$addRole('hod',$r['department']);}
  if($event!=='Submitted')foreach(all('SELECT DISTINCT u.email FROM sessions s JOIN users u ON u.id=s.faculty_id WHERE request_id=?',[$id])as $u)$emails[]=$u['email'];
  if($r['status']==='Approved'){$addRole('president');$addRole('administration');}
 }else{$addRole('daa');if($r['module']==='consultation'){$t=one('SELECT email FROM users WHERE id=?',[$r['receiver_id']]);if($t)$emails[]=$t['email'];}else $addRole($r['module']==='exam'?'exam':'librarian');}
 $link=rtrim($config['url'],'/').'/?page=detail&module='.$r['module'].'&id='.$id;
 $body=$r['name']."'s request #".$id.' is '.requestStatusLabel($r['status']).".\n\n";
 if($r['module']==='substitution')$body.='Approval route: '.match(requestApprovalRoute($r)){'hod_daa'=>'HoD applicant → DAA','daa_president'=>'DAA applicant → President',default=>'Faculty → HoD → DAA'}.".\n";
 $body.="View request (officials sign in to review or approve): ".$link."\n\nHelp & Manuals: ".rtrim($config['url'],'/').'/?page=help';
 foreach(array_unique($emails)as $email)q('INSERT INTO outbox(recipient,subject,body,created_at) VALUES(?,?,?,?)',[$email,modules()[$r['module']].' #'.$id.' — '.$event,$body,now()]);
}
function required(array $p,string $key):string {$v=trim((string)($p[$key]??''));if($v==='')throw new RuntimeException('Please complete '.str_replace('_',' ',$key).'.');if(mb_strlen($v)>10000)throw new RuntimeException('Field is too long.');return $v;}
function dateValid(string $s):bool{$d=DateTimeImmutable::createFromFormat('!Y-m-d',$s);return $d&&$d->format('Y-m-d')===$s;}
function timeValid(string $s):bool{return (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$s);}
function storeUpload(string $field,?int $request,?int $document,string $label):void {if(empty($_FILES[$field])||$_FILES[$field]['error']===UPLOAD_ERR_NO_FILE)return;$f=$_FILES[$field];if($f['error']!==UPLOAD_ERR_OK||$f['size']>20*1024*1024)throw new RuntimeException('Upload failed. Maximum file size is 20 MB.');$mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);$ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));$allowed=['pdf'=>['application/pdf'],'docx'=>['application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip'],'xlsx'=>['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip'],'png'=>['image/png'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg']];if(!isset($allowed[$ext])||!in_array($mime,$allowed[$ext],true))throw new RuntimeException('Use a PDF, DOCX, XLSX, PNG or JPEG file.');$path=bin2hex(random_bytes(24));if(!move_uploaded_file($f['tmp_name'],__DIR__.'/../storage/uploads/'.$path))throw new RuntimeException('Unable to save file.');q('INSERT INTO files(request_id,document_id,name,path,mime,size,label) VALUES(?,?,?,?,?,?,?)',[$request,$document,basename($f['name']),$path,$mime,$f['size'],$label]);}
function transition(array $u,int $id,string $target,string $note):void {$r=one('SELECT * FROM requests WHERE id=?',[$id]);if(!$r||!canView($u,$r))throw new RuntimeException('Record not available.');$allowed=[];if($r['module']==='substitution'){$allowed=array_keys(substitutionActions($u,$r));}elseif(($r['module']==='exam'&&roles($u,['admin','exam']))||($r['module']==='library'&&roles($u,['admin','librarian']))){if(!in_array($r['status'],['Completed','Cancelled']))$allowed=['In Progress','Needs Information','Completed','Cancelled'];}elseif($r['module']==='consultation'&&((int)$r['receiver_id']===(int)$u['id']||roles($u,['admin','daa']))){if($r['status']==='Requested')$allowed=['Confirmed','Cancelled'];if($r['status']==='Confirmed')$allowed=['Completed','No Show','Cancelled'];}if(!in_array($target,$allowed,true))throw new RuntimeException('This action is not available to your role or the request has already changed.');if((str_contains($target,'Rejected')||$target==='Cancelled')&&trim($note)==='')throw new RuntimeException('Please provide a reason.');$s=q('UPDATE requests SET status=?,updated_at=? WHERE id=? AND status=? AND department=?',[$target,now(),$id,$r['status'],$r['department']]);if($s->rowCount()!==1)throw new RuntimeException('Another official already updated this request. Refresh the page.');if($target==='Cancelled'){q('DELETE FROM bookings WHERE request_id=?',[$id]);if(attReady())q('DELETE FROM consultation_appointments WHERE request_id=?',[$id]);}audit($id,(int)$u['id'],$target,$note);notifyRequest($id,$target);if($r['module']==='substitution'&&$target==='Approved')queueOfficeOrder($id);}

function officialLogin(array $u):bool {return !in_array($u['role'],['faculty','student','guest'],true) && (int)$u['active']===1;}
function publicApplicant(array $p):array {
 if(($p['module']??'')!=='consultation'){
  $u=one("SELECT * FROM users WHERE id=? AND active=1 AND role NOT IN ('student','guest')",[(int)($p['applicant_id']??0)]);
  if(!$u)throw new RuntimeException('Select an active staff member.');
  if(strcasecmp(required($p,'applicant_email'),$u['email'])!==0)throw new RuntimeException('Enter the official email registered for the selected staff member.');
  return $u;
 }
 $name=required($p,'applicant_name');$email=required($p,'applicant_email');
 if(mb_strlen($name)>160||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)throw new RuntimeException('Enter a valid name and email address.');
 $u=one('SELECT * FROM users WHERE email=?',[$email]);
 if($u){if(!$u['active'])throw new RuntimeException('This contact is inactive. Contact the administrator.');return $u;}
 q('INSERT INTO users(name,email,password,role,department) VALUES(?,?,?,?,?)',[$name,$email,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),'student','']);
 return one('SELECT * FROM users WHERE id=?',[(int)db()->lastInsertId()]);
}
function saveTeachingStaff(array $actor,array $p):void {
 if($actor['role']!=='admin')throw new RuntimeException('Administrator access required.');
 $id=(int)($p['id']??0);if($id&&!one("SELECT id FROM users WHERE id=? AND role='faculty'",[$id]))throw new RuntimeException('Teaching staff member not found.');
 $name=required($p,'name');$email=required($p,'email');$department=required($p,'department');
 if(mb_strlen($name)>160||strlen($email)>190||!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($department,departments(),true))throw new RuntimeException('Check name, official email and department.');
 if($id){$old=one('SELECT * FROM users WHERE id=?',[$id]);transferPendingDepartment($id,$old['department'],$department,(int)$actor['id']);q('UPDATE users SET name=?,email=?,department=? WHERE id=?',[$name,$email,$department,$id]);}
 else q('INSERT INTO users(name,email,password,role,department) VALUES(?,?,?,?,?)',[$name,$email,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),'faculty',$department]);
 saveStaffProfile($id?:((int)db()->lastInsertId()),$p);
}
function deleteTeachingStaff(array $actor,int $id):void {setStaffStatus($actor,$id,false);}

function accountRoles():array{return ['faculty','admin','daa','hod','pl','exam','librarian','president','administration','lab','alumnus','student'];}
function saveStaffAccount(array $actor,array $p):void{
 if($actor['role']!=='admin')throw new RuntimeException('Administrator access required.');
 $id=(int)($p['id']??0);$old=one("SELECT * FROM users WHERE id=? AND role NOT IN ('student','guest')",[$id]);
 if(!$old)throw new RuntimeException('Staff entry not found.');
 if($id===(int)$actor['id'])throw new RuntimeException('Another administrator must change your account permissions.');
 $role=required($p,'role');$dept=trim($p['department']??'');$email=required($p,'email');$name=required($p,'name');$password=$p['reset_password']??'';
 if(!in_array($role,accountRoles(),true)||($dept&&!in_array($dept,accountDepartments(),true)))throw new RuntimeException('Invalid role or department.');
 if(in_array($role,['hod','pl'],true)&&!$dept)throw new RuntimeException('Select the department for HoD or PL approval routing.');
 if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190||mb_strlen($name)>160)throw new RuntimeException('Check name and email.');
 if(one('SELECT id FROM users WHERE LOWER(email)=LOWER(?) AND id<>?',[$email,$id]))throw new RuntimeException('This email already belongs to another entry. Edit that existing entry.');
 if(($old['role']==='faculty'&&$role!=='faculty'&&strlen($password)<12)||($password!==''&&strlen($password)<12))throw new RuntimeException('Set a password of at least 12 characters when granting official login access.');
 saveStaffProfile($id,$p);transferPendingDepartment($id,$old['department'],$dept,(int)$actor['id']);
 q('UPDATE users SET name=?,email=?,role=?,department=?,active=? WHERE id=?',[$name,$email,$role,$dept,!empty($p['active'])?1:0,$id]);
 setStaffStatus($actor,$id,!empty($p['active']),$p['status_reason']??staffProfile($id)['status_reason']);
 if($password!=='')q('UPDATE users SET password=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$id]);
 if(attReady()){
  $enabled=!empty($p['teaching_enabled'])?1:0;
  if(one('SELECT user_id FROM tutor_permissions WHERE user_id=?',[$id]))q('UPDATE tutor_permissions SET enabled=? WHERE user_id=?',[$enabled,$id]);else q('INSERT INTO tutor_permissions(user_id,enabled) VALUES(?,?)',[$id,$enabled]);
  if(!$enabled||empty($p['active']))q('UPDATE tutor_registered_devices SET active=0,revoked_at=? WHERE staff_id=?',[now(),$id]);
  attAudit((int)$actor['id'],'Staff role and teaching access changed',$id,['previous_role'=>$old['role'],'role'=>$role,'previous_department'=>$old['department'],'department'=>$dept,'staff_type'=>staffProfile($id)['staff_type'],'teaching_enabled'=>$enabled,'active'=>!empty($p['active'])]);
 }
}
function teachingDirectory():array{
 $users=all("SELECT * FROM users WHERE role NOT IN ('student','guest') ORDER BY active DESC,name");
 return array_values(array_filter($users,fn($u)=>in_array($u['role'],['faculty','daa','hod','pl'],true)||(attReady()&&(bool)one('SELECT user_id FROM tutor_permissions WHERE user_id=? AND enabled=1',[$u['id']]))));
}



require_once __DIR__.'/results/core.php';

require_once __DIR__.'/research/workflow.php';
require_once __DIR__.'/research/timeline.php';

require_once __DIR__.'/lab/core.php';

require_once __DIR__.'/desks/registration.php';
