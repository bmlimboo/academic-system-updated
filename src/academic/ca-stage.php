<?php
function acPendingESE(array $r):bool{
 if($r['kind']!=='assessment'||empty($r['metadata']['has_exam'])||!$r['entries'])return false;
 foreach($r['entries'] as $v)foreach($r['metadata']['components'] as $i=>$c)if($c['type']==='ESE'&&($v['marks'][$i]??null)===null)return true;
 return false;
}
function acCanAddESE(?array $u,array $r):bool{return $u&&!empty($u['active'])&&$r['status']==='Submitted'&&acPendingESE($r)&&(acAdmin($u)||(acTutor($u)&&(int)$r['owner_id']===(int)$u['id']));}
function acApplyESE(array $r,array $rows):array{
 $headers=['Student ID','Student Name'];$indices=[];foreach($r['metadata']['components'] as $i=>$c)if($c['type']==='ESE'){$headers[]=$c['name'];$indices[]=$i;}
 acAssert(count($rows)>1&&array_map('trim',$rows[0])===$headers,'Use these exact columns: '.implode(', ',$headers));
 $old=[];foreach($r['entries'] as $v)$old[$v['student_id']]=$v;$seen=[];
 foreach(array_slice($rows,1) as $row){if(!array_filter($row,fn($v)=>trim((string)$v)!==''))continue;
 acAssert(count($row)===count($headers),'ESE row has the wrong number of columns.');$id=trim((string)$row[0]);acAssert(isset($old[$id])&&!isset($seen[$id]),'Unknown or duplicate student ID: '.$id);acAssert(trim((string)$row[1])===$old[$id]['student_name'],'Student name differs for '.$id);$seen[$id]=true;
 foreach($indices as $column=>$index){$raw=trim((string)$row[$column+2]);acAssert($raw!=='','Enter ESE marks for '.$id);$value=acNumber($raw,$headers[$column+2],0,$r['metadata']['components'][$index]['max']);$before=$old[$id]['marks'][$index];acAssert($before===null||(float)$before===(float)$value,'Previously submitted ESE marks cannot be changed for '.$id);$old[$id]['marks'][$index]=$value;}
 }
 acAssert(count($seen)===count($old),'Include every student from the CA submission.');$entries=array_values($old);acValidateEntries($r,$entries,true);return $entries;
}
