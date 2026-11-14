<?php
require_once __DIR__.'/../src/bootstrap.php';
$driver=db()->getAttribute(PDO::ATTR_DRIVER_NAME);
$columns=function(string $table)use($driver):array{
 if($driver==='mysql')return array_column(all("SHOW COLUMNS FROM `$table`"),'Field');
 return array_column(all("PRAGMA table_info(`$table`)"),'name');
};
foreach(['exam_windows'=>['submission_round'=>"INTEGER NOT NULL DEFAULT 1"],'exam_papers'=>['submission_round'=>"INTEGER NOT NULL DEFAULT 1"]]as $table=>$wanted){
 $existing=$columns($table);
 foreach($wanted as $name=>$definition)if(!in_array($name,$existing,true))db()->exec("ALTER TABLE `$table` ADD COLUMN `$name` $definition");
}
echo "Exam reopened-submission rounds migration complete. Existing submissions remain in round 1.\n";
