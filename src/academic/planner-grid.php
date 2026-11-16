<?php
// Keep original entry indices so changing the layout cannot remap saved absences.
$columns=[];$students=[];
foreach($entries as $entryIndex=>$entry){
 $sid=(string)$entry['student_id'];$students[$sid]??=['id'=>$sid,'name'=>$entry['student_name'],'cells'=>[]];
 foreach($entry['periods'] as $period=>$mark){if($mark==='')continue;
  $key=$entry['date'].'|'.$period.'|'.$entry['class_type'].'|'.$entry['classroom'];
  $columns[$key]=['date'=>$entry['date'],'period'=>$period,'type'=>$entry['class_type'],'room'=>$entry['classroom']];
  $students[$sid]['cells'][$key]=['key'=>$entryIndex.'|'.$period,'mark'=>$mark];
 }
}
ksort($columns);$dates=[];foreach($columns as $column)$dates[$column['date']]=($dates[$column['date']]??0)+1;
?>
<link rel="stylesheet" href="assets/attendance-grid.css?v=1">
<link rel="stylesheet" href="assets/attendance-grid-sticky.css?v=1">
<p>One row per student. Scroll sideways for more dates. <?=!empty($readOnly)?'P = Present; A = Absent.':'Ticked / P = Present; unticked / A = Absent.'?> A dash means no entry for that student and session.</p>
<div class="attendance-grid-scroll" tabindex="0" role="region" aria-label="Attendance register; scroll for additional dates">
<table class="attendance-grid"><thead>
<tr><th colspan="3" class="grid-date-label">Date</th><?php foreach($dates as $date=>$count):?><th colspan="<?=$count?>" scope="colgroup"><?=e($date)?></th><?php endforeach;?></tr>
<tr><th scope="col">Sl. No.</th><th scope="col">Student ID</th><th scope="col">Student Name</th><?php foreach($columns as $column):?><th scope="col"><?=e($column['period'])?></th><?php endforeach;?></tr>
<tr><th colspan="3" class="grid-date-label">Class Type</th><?php foreach($columns as $column):?><th><?=e($column['type'])?></th><?php endforeach;?></tr>
<tr><th colspan="3" class="grid-date-label">Classroom</th><?php foreach($columns as $column):?><th><?=e($column['room'])?></th><?php endforeach;?></tr>
</thead><tbody>
<?php foreach(array_values($students) as $serial=>$student):?><tr><td><?=$serial+1?></td><td><?=e($student['id'])?></td><th scope="row"><?=e($student['name'])?></th><?php foreach($columns as $key=>$column):$cell=$student['cells'][$key]??null;?><td><?php if($cell&&!empty($readOnly)):?><strong><?=e($cell['mark'])?></strong><?php elseif($cell):?><label class="attendance-grid-cell"><input type="checkbox" data-attendance-cell="<?=e($cell['key'])?>" <?=$cell['mark']==='P'?'checked':''?> aria-label="<?=e($student['name'].' · '.$column['date'].' · '.$column['period'].' · Present')?>"><span class="grid-present" aria-hidden="true">P</span><span class="grid-absent" aria-hidden="true">A</span></label><?php else:?>—<?php endif;?></td><?php endforeach;?></tr><?php endforeach;?>
</tbody></table></div>
<script src="assets/attendance-grid-sticky.js?v=1" defer></script>
