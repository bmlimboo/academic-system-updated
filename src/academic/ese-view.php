<?php
$raw=one('SELECT * FROM academic_records WHERE id=?',[(int)($_GET['id']??0)]);
if(!$raw){echo '<p>Record not found.</p>';return;}$r=acHydrate($raw);
if(!acCanAddESE($user,$r)){echo '<p>No missing ESE marks are available for your account. Submitted marks remain locked.</p>';return;}
$headers=['Student ID','Student Name'];foreach($r['metadata']['components'] as $c)if($c['type']==='ESE')$headers[]=$c['name'];
?>
<section class="card"><h2>CA submitted — add ESE marks</h2><p><?=e($r['module_code'].' · '.$r['module_name'])?></p><p>Prepare a CSV or Excel file with these columns in this order:</p><p><strong><?=e(implode(', ',$headers))?></strong></p><p>Include every student using the same ID and name as the CA submission. Enter raw ESE marks. Original CA marks remain locked. Blank ESE marks keep the result incomplete until this upload is submitted.</p>
<?php foreach($r['metadata']['components'] as $c):if($c['type']==='ESE'):?><p><?=e($c['name'])?>: maximum <?=e($c['max'])?>; weight <?=e($c['weight'])?>%.</p><?php endif;endforeach;?>
<form method="post" action="?page=ac_record&amp;id=<?=$r['id']?>&amp;ese=1" enctype="multipart/form-data"><?php acHidden($r,'ac_add_ese');?><label>ESE marks file<input type="file" name="sheet" accept=".csv,.xlsx" required></label><label class="period-delete-confirm"><input type="checkbox" name="confirmed" value="1" required><span>I have checked all ESE marks and confirm submission.</span></label><button>Submit ESE marks</button></form></section>
