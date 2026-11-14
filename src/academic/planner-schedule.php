<?php
function spBlock(array $slot):bool{return ($slot['period']??'')==='Block (6 hours)';}
function spTimes(string $period):array{return $period==='Block (6 hours)'?['00:00','24:00']:explode('-',$period);}
function spWeekly(array $slot):bool{return $slot['class_type']==='Regular'&&(int)$slot['weekday']>0;}
function spSlotBounds(array $r,array $slot):array{
 if(spWeekly($slot)){[$a,$b]=explode('-',$r['academic_year']);$slot['starts_on']=$a.'-01-01';$slot['ends_on']=$b.'-12-31';}return $slot;
}
function spOnDay(array $slot,string $day):bool{return $day>=$slot['starts_on']&&$day<=$slot['ends_on']&&(!(int)$slot['weekday']||(int)date('N',strtotime($day))===(int)$slot['weekday']);}
function spReplacesRegular(array $a,array $b):bool{
 if((spBlock($a)&&spWeekly($b))||(spBlock($b)&&spWeekly($a)))return true;if($a['period']!==$b['period'])return false;
 return (spWeekly($a)&&in_array($b['class_type'],['Substitution','Block Day','Block Week','Practical'],true))||(spWeekly($b)&&in_array($a['class_type'],['Substitution','Block Day','Block Week','Practical'],true));
}
function spDaySlots(array $r,array $slots,string $day):array{
 $blocks=array_values(array_filter($slots,fn($s)=>spBlock($s)&&spOnDay($s,$day)));acAssert(count($blocks)<=1,'Overlapping block classes on '.$day.'.');if($blocks){foreach($slots as $s)if(spOnDay(spSlotBounds($r,$s),$day)&&!spWeekly($s))acAssert(spBlock($s),'Block attendance overlaps another special class on '.$day.'.');return $blocks;}
 $selected=[];foreach($slots as $slot){$slot=spSlotBounds($r,$slot);if(!spOnDay($slot,$day))continue;$period=$slot['period'];
  if(isset($selected[$period])){acAssert(spReplacesRegular($selected[$period],$slot),'Conflicting timetable sessions on '.$day.' at '.$period.'.');if(!spWeekly($slot))$selected[$period]=$slot;}else $selected[$period]=$slot;
 }
 ksort($selected);$previousEnd=null;foreach($selected as $slot){[$start,$end]=spTimes($slot['period']);acAssert($previousEnd===null||$start>=$previousEnd,'Overlapping timetable periods on '.$day.'.');$previousEnd=$end;}
 return array_values($selected);
}
function spValidateSlot(array $r,array $candidate,int $except,bool $retainAttendance=false):void{
 $candidate=spSlotBounds($r,$candidate);
 foreach(all('SELECT * FROM attendance_timetable WHERE record_id=? AND id<>?',[$r['id'],$except])as $other){$other=spSlotBounds($r,$other);
  if($other['ends_on']<$candidate['starts_on']||$other['starts_on']>$candidate['ends_on'])continue;
  [$a,$b]=spTimes($candidate['period']);[$c,$d]=spTimes($other['period']);if($a>=$d||$b<=$c)continue;
  for($day=max($candidate['starts_on'],$other['starts_on']);$day<=min($candidate['ends_on'],$other['ends_on']);$day=date('Y-m-d',strtotime($day.' +1 day'))){
   if(!spOnDay($candidate,$day)||!spOnDay($other,$day))continue;
   acAssert(spReplacesRegular($candidate,$other),'Overlapping timetable period on '.$day.'. Special classes may replace an identical regular period; extra classes need a separate time.');
   // Never silently relabel an existing draft or submitted regular session.
   $check=$candidate;$check['starts_on']=$check['ends_on']=$day;$check['weekday']=0;
   acAssert($retainAttendance||!spUsedOnDate($r,$check,$day),'Attendance already exists for '.$day.' at '.$candidate['period'].'. Remove that attendance first, or discard the draft, before changing this session.');
  }
 }
}

function spUsedOnDate(array $r,array $slot,string $day):bool{ $entries=$r['entries'];$draft=one('SELECT entries FROM attendance_drafts WHERE record_id=?',[$r['id']]);if($draft)$entries=array_merge($entries,acDecode($draft['entries']));foreach($entries as $v)if($v['date']===$day&&(spBlock($slot)?(bool)array_filter($v['periods']):(!empty($v['periods'][$slot['period']])||!empty($v['periods']['Block (6 hours)']))))return true;return false;}
