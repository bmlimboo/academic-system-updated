<?php
$editing=one('SELECT * FROM attendance_timetable WHERE id=? AND record_id=?',[(int)($_GET['slot']??0),$id])?:null;
$periodOptions=array_column(all('SELECT label FROM attendance_periods WHERE active=1 ORDER BY label'),'label');
function spSlotActions(array $r,array $slot):void{
 $used=spUsed($r,$slot);
 echo '<div class="schedule-actions"><a class="button secondary" href="?page=sa_record&id='.(int)$r['id'].'&slot='.(int)$slot['id'].'#schedule-'.(spWeekly($slot)?'regular':'special').'">Edit</a>';
 spForm('sa_plan_slot_delete',$r);if($used)echo '<details><summary>Delete timetable entry</summary><p class="notice">Attendance exists. Deleting this timetable entry stops future generation from it. Saved attendance and drafts will remain.</p><label class="period-delete-confirm"><input type="checkbox" name="confirm_used_slot" value="1" required><span>I confirm deletion of the timetable entry only.</span></label>';echo '<input type="hidden" name="slot_id" value="'.(int)$slot['id'].'"><button class="danger-button">Delete</button>';if($used)echo '</details>';echo '</form></div>';
}
?>
<link rel="stylesheet" href="assets/attendance-timetable.css?v=1">
<?php foreach(['regular','special'] as $mode):$regular=$mode==='regular';$list=array_values(array_filter($slots,fn($slot)=>spWeekly($slot)===$regular));$edit=$editing&&spWeekly($editing)===$regular?$editing:['classroom'=>$r['metadata']['classroom']];?>
<section class="card schedule-card" id="schedule-<?=$mode?>">
<div class="schedule-heading"><h2><?=$regular?'Weekly timetable — Regular classes':'Special classes'?></h2><span class="schedule-count"><?=count($list)?> period<?=count($list)===1?'':'s'?></span></div>
<p><?=$regular?'Enter the day, period and classroom. These classes repeat weekly within this module’s academic year; choose the actual attendance dates when generating attendance.':'Enter Block Day, Block Week, Substitution, Practical or Extra classes here. These are included automatically when their dates fall within the attendance range.'?></p>
<div class="schedule-table-wrap"><table class="schedule-table"><thead><tr><th>No.</th><th>Day</th><?php if(!$regular):?><th>Dates</th><?php endif;?><th>Period</th><?php if(!$regular):?><th>Class type</th><?php endif;?><th>Classroom</th><th>Actions</th></tr></thead><tbody>
<?php foreach($list as $number=>$slot):?><tr><td><?=$number+1?></td><td><?=e($days[(int)$slot['weekday']])?></td><?php if(!$regular):?><td><?=e($slot['starts_on'])?><?php if($slot['ends_on']!==$slot['starts_on']):?><br>to <?=e($slot['ends_on'])?><?php endif;?></td><?php endif;?><td class="schedule-period"><?=e($slot['period'])?></td><?php if(!$regular):?><td><span class="schedule-type"><?=e($slot['class_type'])?></span></td><?php endif;?><td><?=e($slot['classroom'])?></td><td><?php spSlotActions($r,$slot);?></td></tr><?php endforeach;if(!$list):?><tr><td colspan="<?=$regular?5:7?>">No <?=$regular?'regular periods':'special classes'?> added yet.</td></tr><?php endif;?></tbody></table></div>
<h3><?=isset($edit['id'])?'Edit this period':($regular?'Add regular period':'Add special class')?></h3>
<?php spForm('sa_plan_slot',$r);?><input type="hidden" name="slot_id" value="<?=$edit['id']??0?>"><input type="hidden" name="schedule_mode" value="<?=$mode?>"><div class="form-grid"><?php if(!$regular)acField('class_type','Class type',$edit,['Block Day','Block Week','Substitution','Practical','Extra']);?>
<label><?=$regular?'Day':'Day / date selection'?><select name="weekday" required><?php foreach($days as $key=>$label):if($regular&&$key===0)continue;?><option value="<?=$key?>" <?=(int)($edit['weekday']??($regular?1:0))===$key?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label>
<?php if(!$regular){acField('starts_on','From date',$edit,[],'date');acField('ends_on','Until date',$edit,[],'date');}acField('period','Period time',$edit,$periodOptions);acField('classroom',$regular?'Classroom':'Classroom / field visit location',$edit);?></div>
<?php if(!$regular):?><p class="schedule-help">Block Day and Block Week accept any date range, including one day. For one day, select the same From and Until date. Each block date counts as 6 hours and replaces regular classes for that day. Weekends are included. For other single classes, use the same start and end date. “Every date in range” includes weekends. A special class at the exact time of a regular class replaces that occurrence, except Extra, which must use another time.</p><?php endif;?>
<?php if(isset($edit['id'])&&spUsed($r,$edit)):?><p class="notice">Attendance exists for this entry. Changes apply to the timetable only. Saved attendance and drafts keep their original dates, times, class types and locations.</p><label class="period-delete-confirm"><input type="checkbox" name="confirm_used_slot" value="1" required><span>I confirm this timetable change; existing attendance will remain unchanged.</span></label><?php endif;?>
<button><?=isset($edit['id'])?'Save changes':($regular?'Add regular period':'Add special class')?></button><?php if(isset($edit['id'])):?><a class="button secondary" href="?page=sa_record&id=<?=$id?>">Cancel editing</a><?php endif;?></form>
</section>
<?php endforeach;?>

<script src="assets/attendance-block.js" defer></script>
