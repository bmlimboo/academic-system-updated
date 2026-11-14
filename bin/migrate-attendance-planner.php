<?php
require_once __DIR__.'/../src/bootstrap.php';
$sql=file_get_contents(__DIR__.'/../migrations/018_attendance_planner.sql');
if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$sql=str_replace('INTEGER PRIMARY KEY AUTOINCREMENT','INTEGER PRIMARY KEY AUTO_INCREMENT',$sql);
db()->exec($sql);
require __DIR__.'/migrate-student-gender.php';
foreach(['08:00-09:00','09:00-10:00','10:15-11:15','11:15-12:15','13:15-14:15','14:15-15:15','15:30-16:30','16:30-17:30'] as $label)if(!one('SELECT label FROM attendance_periods WHERE label=?',[$label]))q('INSERT INTO attendance_periods(label) VALUES(?)',[$label]);
// Move legacy unsubmitted attendance into the resumable checklist, exactly once.
// Submitted records and their student entries are never moved.
db()->beginTransaction();
try {
 foreach(all("SELECT id,entries,revision FROM academic_records WHERE kind='attendance' AND status='Draft'") as $record){
  if(!acDecode($record['entries'])||one('SELECT record_id FROM attendance_drafts WHERE record_id=?',[$record['id']]))continue;
  q('INSERT INTO attendance_drafts(record_id,entries,revision,updated_at) VALUES(?,?,?,?)',[$record['id'],$record['entries'],$record['revision']+1,now()]);
  q("UPDATE academic_records SET entries='[]',revision=revision+1 WHERE id=?",[$record['id']]);
 }
 db()->commit();
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
echo "Attendance timetable and student register ready. Existing records retained.\n";
