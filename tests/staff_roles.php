<?php
$path=sys_get_temp_dir().'/roles-'.bin2hex(random_bytes(5)).'.sqlite';putenv('APP_DSN=sqlite:'.$path);
require __DIR__.'/../src/bootstrap.php';db()->exec(file_get_contents(__DIR__.'/../src/schema.sql'));require __DIR__.'/../bin/migrate.php';
$n=0;function checkRole($ok,$message){global $n;if(!$ok)throw new RuntimeException($message);$n++;}
function rejectRole($fn,$message){$rejected=false;db()->beginTransaction();try{$fn();}catch(RuntimeException $e){$rejected=true;}finally{db()->rollBack();}checkRole($rejected,$message);}
q('INSERT INTO users(name,email,password,role,department) VALUES(?,?,?,?,?)',['Admin','admin@test.bt',password_hash('TestPassword123!',PASSWORD_DEFAULT),'admin',departments()[0]]);$admin=one('SELECT * FROM users WHERE id=1');
saveTeachingStaff($admin,['name'=>'Existing Teacher','email'=>'teacher@test.bt','department'=>departments()[0]]);$id=(int)db()->lastInsertId();$teacher=one('SELECT * FROM users WHERE id=?',[$id]);
$p=['id'=>$id,'name'=>'Existing Teacher','email'=>'teacher@test.bt','department'=>departments()[0],'role'=>'daa','active'=>1,'teaching_enabled'=>1];
rejectRole(fn()=>saveStaffAccount($admin,$p),'promotion requires usable official password');
$p['reset_password']='TeacherOfficial123!';
foreach(['daa','hod','pl']as $role){$p['role']=$role;db()->beginTransaction();saveStaffAccount($admin,$p);db()->commit();$u=one('SELECT * FROM users WHERE id=?',[$id]);checkRole($u['role']===$role&&teaching($u)&&officialLogin($u),$role.' retains teaching and official login');checkRole(in_array($id,array_column(teachingDirectory(),'id')), $role.' visible in teaching directory');}
checkRole((int)q('SELECT COUNT(*) FROM users')->fetchColumn()===2,'role assignment reuses staff identity');
rejectRole(fn()=>saveStaffAccount($teacher,$p),'faculty cannot change roles');
rejectRole(fn()=>saveStaffAccount($admin,array_merge($p,['department'=>''])),'HoD/PL requires department');
rejectRole(fn()=>saveStaffAccount($admin,array_merge($p,['email'=>'admin@test.bt'])),'duplicate email rejected');
rejectRole(fn()=>saveStaffAccount($admin,array_merge($p,['id'=>1])),'self permission change rejected');
$p['role']='faculty';$p['reset_password']='';db()->beginTransaction();saveStaffAccount($admin,$p);db()->commit();$u=one('SELECT * FROM users WHERE id=?',[$id]);checkRole(teaching($u)&&!officialLogin($u),'return to faculty retains teaching but revokes official login');
unset($p['teaching_enabled']);db()->beginTransaction();saveStaffAccount($admin,$p);db()->commit();checkRole(!teaching($u),'explicit teaching access removal');
unlink($path);echo "$n staff-role checks passed.\n";
