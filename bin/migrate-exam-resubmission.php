<?php
// Adds the Exam Cell "allow resubmission" switch to exam_windows. Safe to run more than once.
require_once __DIR__.'/../src/bootstrap.php';
$mysql=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
$columns=fn($table)=>$mysql?array_column(all('SHOW COLUMNS FROM '.$table),'Field'):array_column(all('PRAGMA table_info('.$table.')'),'name');
if(!in_array('resubmit_enabled',$columns('exam_windows'),true))db()->exec('ALTER TABLE exam_windows ADD COLUMN resubmit_enabled INTEGER NOT NULL DEFAULT 0');
echo "Exam resubmission migration complete.\n";
