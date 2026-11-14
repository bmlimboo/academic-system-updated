<?php
require __DIR__.'/../src/bootstrap.php';
$schema=file_get_contents(__DIR__.'/../src/schema.sql');if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$schema=str_replace('INTEGER PRIMARY KEY AUTOINCREMENT','INTEGER PRIMARY KEY AUTO_INCREMENT',$schema);db()->exec($schema);
if(!one('SELECT id FROM users LIMIT 1')){
$email=getenv('ADMIN_EMAIL');$password=getenv('ADMIN_PASSWORD');if(!$email||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password?:'')<12)exit("Set ADMIN_EMAIL and ADMIN_PASSWORD (at least 12 characters), then rerun. Schema created.\n");q('INSERT INTO users(name,email,password,role) VALUES(?,?,?,?)',['System Administrator',$email,password_hash($password,PASSWORD_DEFAULT),'admin']);}
echo "Database ready.\n";

require __DIR__.'/migrate.php';
