<?php
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/import.php';
$r=['kind'=>'attendance','academic_year'=>'2026-2027','metadata'=>['classroom'=>'Lab'],'contact_hours'=>10];$n=0;
foreach(acClassTypes() as $type){
$rows=[['Date','','','2026-10-01','2026-10-01'],['Sl. No.','Student ID','Student Name','08:00-09:00','09:00-10:00'],['Class Type','','',$type,$type],['Classroom','','','Lab','Lab'],[1,'001','Test','P','A']];
$entries=saParseMatrix($r,$rows);$sample=$r+['entries'=>$entries];$flat=acParseRows($r,[acHeaders($r),...acSheetRows($sample)]);
if($flat!==$entries||saParseMatrix($r,saMatrix($sample))!==$entries)throw new Exception('Roundtrip '.$type);
$a=acAttendance($sample)['students'][0];if($a['recorded_percentage']!==50.0||$a['present']!==1||$a['absent']!==1||$a['extra_present']!==($type==='Extra'?1:0))throw new Exception('Calculation '.$type);
$duplicate=$entries[0];$duplicate['class_type']=$type==='Regular'?'Substitution':'Regular';try{acValidateEntries($r,[$entries[0],$duplicate],true);throw new LogicException('Overlap accepted');}catch(RuntimeException $e){}
$n+=3;
}
echo "$n class type parsing, calculation and overlap checks passed.\n";
