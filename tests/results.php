<?php
$path=sys_get_temp_dir().'/results-'.bin2hex(random_bytes(5)).'.sqlite';putenv('APP_DSN=sqlite:'.$path);require __DIR__.'/../src/bootstrap.php';
$n=0;function ck($v,$m){global $n;if(!$v)throw new RuntimeException($m);$n++;}function fails($fn,$m){try{$fn();}catch(RuntimeException $e){ck(true,$m);return;}ck(false,$m);}
$head=rtHeaders();$row=['001','Test Student',1,'AA101','Module One',2026,100,80,12];
$r=rtParse([$head,$row],2);ck($r[0]['student_number']==='001','leading zero');
foreach([2=>[30,70],3=>[20,30,50],4=>[10,20,30,40]]as $duration=>$weights){$rr=[];for($i=1;$i<=$duration*2;$i++){$v=$r[0];$v['semester']=$i;$v['mark']=40+10*ceil($i/2);$rr[]=$v;}$report=rtReport(['duration'=>$duration],$rr)['001'];$expected=0;foreach($weights as $i=>$w)$expected+=(50+10*$i)*$w/100;ck(abs($report['percent']-$expected)<0.000001,'year weight formula '.$duration);ck($report['complete'],'all semesters present');array_pop($rr);ck(!rtReport(['duration'=>$duration],$rr)['001']['complete'],'missing semester');}
foreach([80=>'Outstanding',70=>'Very Good',60=>'Good',50=>'Satisfactory',49=>'Fail']as $mark=>$label){$v=$r[0];$v['mark']=$mark;ck(rtReport(['duration'=>2],[$v])['001']['performance']===$label,'performance '.$mark);}
$other=$r[0];$other['student_number']='002';$other['module_code']='AA102';$rep=rtReport(['duration'=>2],[$r[0],$other]);ck($rep['001']['missing']===1&&$rep['002']['missing']===1,'missing module comparison');
foreach([[2,9],[5,0],[6,0],[7,101],[8,-1],[1,''],[0,'=EVIL()']]as [$col,$value]){$bad=$row;$bad[$col]=$value;fails(fn()=>rtParse([$head,$bad],2),'bad upload');}
fails(fn()=>rtParse([$head,$row,$row],2),'duplicate');$changed=$r[0];$changed['mark']=81;fails(fn()=>rtMerge($r,[$changed],false),'conflict');ck(rtMerge($r,[$changed],true)['changed']===1,'correction');ck(rtMerge($r,$r,false)['same']===1,'idempotent');$changed=$r[0];$changed['student_number']='003';$changed['module_title']='Wrong title';fails(fn()=>rtMerge($r,[$changed],false),'module mismatch');
foreach(['faculty','pl','student','president','librarian']as $role)ck(!rtAllowed(['role'=>$role,'active'=>1]),'access '.$role);foreach(['exam','daa','admin']as $role)ck(rtAllowed(['role'=>$role,'active'=>1]),'access '.$role);ck(!rtAllowed(['role'=>'admin','active'=>0]),'inactive admin denied');
$mysql=$r[0];foreach(['maximum','mark','credits']as $k)$mysql[$k]=number_format((float)$mysql[$k],4,'.','');ck(rtMerge([$mysql],$r,false)['same']===1,'MySQL decimal representation reupload');
if(is_file($path))unlink($path);echo "$n results calculation/validation checks passed.\n";
