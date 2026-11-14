<?php
$path=sys_get_temp_dir().'/calendar-'.bin2hex(random_bytes(5)).'.sqlite';putenv('APP_DSN=sqlite:'.$path);require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/import.php';db()->exec(file_get_contents(__DIR__.'/../src/schema.sql'));require __DIR__.'/../bin/migrate.php';
$n=0;function calCheck($ok,$msg){global $n;if(!$ok)throw new RuntimeException($msg);$n++;}function calDenied($fn,$msg){$failed=false;db()->beginTransaction();try{$fn();}catch(RuntimeException $e){$failed=true;}finally{db()->rollBack();}calCheck($failed,$msg);}
q("INSERT INTO users(name,email,password,role,department) VALUES('Admin','admin@test.bt','','admin','')");$admin=one('SELECT * FROM users WHERE id=1');$daa=$admin;$daa['role']='daa';
$term=['academic_year'=>'2026-2027','semester'=>'I','valid_from'=>'2026-08-01','valid_until'=>'2026-12-31'];
calDenied(fn()=>saveTeachingCalendar($daa,$term),'only Admin manages calendar');
calDenied(fn()=>saveTeachingCalendar($admin,array_merge($term,['semester'=>'III'])),'only semesters I and II');
calDenied(fn()=>saveTeachingCalendar($admin,array_merge($term,['valid_until'=>'2026-01-01'])),'reversed term rejected');
saveTeachingCalendar($admin,$term);saveTeachingTime($admin,['start_time'=>'09:00','end_time'=>'10:00']);
calDenied(fn()=>saveTeachingTime($daa,['start_time'=>'10:15','end_time'=>'11:15']),'only Admin manages class timings');
calDenied(fn()=>saveTeachingTime($admin,['start_time'=>'10:00','end_time'=>'09:00']),'reversed class timing rejected');
$p=['calendar_id'=>1,'time_slot_id'=>1,'row'=>['Year'=>'II','Semester'=>'I','Academic Year'=>'FORGED','Start Time'=>'02:00','Valid From'=>'2099-01-01']];$r=manualClassRow($p);calCheck($r['Academic Year']==='2026-2027'&&$r['Start Time']==='09:00'&&$r['Valid From']==='2026-08-01','fixed values derive from server settings');
calDenied(fn()=>manualClassRow(array_replace_recursive($p,['row'=>['Semester'=>'II']])),'calendar semester mismatch rejected');
calDenied(fn()=>manualClassRow(array_replace_recursive($p,['row'=>['Year'=>'V']])),'manual year V rejected');
calDenied(fn()=>manualClassRow(array_replace_recursive($p,['row'=>['Class Date'=>'2027-01-01']])),'one-off date outside term rejected');
q('UPDATE teaching_time_slots SET active=0 WHERE id=1');calDenied(fn()=>manualClassRow($p),'retired timing unavailable');
$csv=$path.'.csv';file_put_contents($csv,"Academic Year,Semester,Year,Valid From,Valid Until\n2026-2027,I,II,2026-08-01,2026-12-31\n");calCheck(count(teachingFileRows($csv,'file.csv'))===2,'upload includes all calendar values in file');file_put_contents($csv,"Academic Year,Semester,Year,Valid From,Valid Until\n2026-2027,I,II,,2026-12-31\n");calDenied(fn()=>teachingFileRows($csv,'file.csv'),'upload cannot rely on missing form dates');unlink($csv);unlink($path);echo "$n teaching calendar checks passed.\n";
