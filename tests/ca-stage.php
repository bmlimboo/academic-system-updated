<?php
require __DIR__.'/../src/bootstrap.php';
$n=0;function ck($v){global $n;if(!$v)throw new Exception('Failed check '.($n+1));$n++;}function denied($fn){try{$fn();}catch(RuntimeException $e){ck(true);return;}throw new Exception('Expected rejection');}
$r=['kind'=>'assessment','owner_id'=>1,'status'=>'Submitted','metadata'=>['has_exam'=>true,'components'=>[['name'=>'Coursework','type'=>'CA','max'=>60,'weight'=>60],['name'=>'Exam','type'=>'ESE','max'=>40,'weight'=>40]]],'entries'=>[['student_id'=>'001','student_name'=>'Test','marks'=>[40,null]]]];
acValidateEntries($r,$r['entries'],true);ck(true);$m=acMarks($r,$r['entries'][0]);ck($m['ca']===40.0&&$m['ese']===null&&$m['total']===null&&$m['result']==='Incomplete');
$bad=$r['entries'];$bad[0]['marks'][0]=null;denied(fn()=>acValidateEntries($r,$bad,true));$ra=$r;$ra['kind']='reassessment';denied(fn()=>acValidateEntries($ra,$ra['entries'],true));
$u=['id'=>1,'role'=>'faculty','active'=>1];ck(acCanAddESE($u,$r));ck(!acCanAddESE($u+[],array_merge($r,['owner_id'=>2])));ck(!acCanAddESE(['id'=>1,'role'=>'faculty','active'=>0],$r));
$rows=[['Student ID','Student Name','Exam'],['001','Test',25]];$e=acApplyESE($r,$rows);ck($e[0]['marks'][0]===40&&$e[0]['marks'][1]==25);ck(acMarks($r,$e[0])['total']===65.0);$done=$r;$done['entries']=$e;ck(!acCanAddESE($u,$done));
foreach([[['Student ID','Student Name','Exam'],['002','Test',25]],[['Student ID','Student Name','Exam'],['001','Test','']],[['Student ID','Student Name','Exam'],['001','Test',41]],[['Student ID','Student Name','Exam'],['001','Wrong',25]]] as $bad)denied(fn()=>acApplyESE($r,$bad));
$headers=acHeaders($r);ck(str_contains($headers[2],'Max. marks: 60; Weightage: 60%'));$new=acParseRows($r,[$headers,['001','Test',40,'']]);$old=acParseRows($r,[['Student ID','Student Name','Coursework','Exam'],['001','Test',40,'']]);ck($new===$old);ck($new[0]['marks'][1]===null);
echo "$n CA/ESE checks passed.\n";
