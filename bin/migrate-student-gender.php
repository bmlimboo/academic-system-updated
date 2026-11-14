<?php
require_once __DIR__.'/../src/bootstrap.php';
$columns=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?array_column(all('SHOW COLUMNS FROM attendance_students'),'Field'):array_column(all('PRAGMA table_info(attendance_students)'),'name');
if(!in_array('gender',$columns,true))db()->exec("ALTER TABLE attendance_students ADD COLUMN gender VARCHAR(30) NOT NULL DEFAULT ''");
echo "Student gender ready. Existing students retained; unspecified gender is blank.\n";
