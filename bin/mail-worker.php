<?php
require __DIR__.'/../src/bootstrap.php';
if(!$config['mail_enabled']||!filter_var($config['mail_from'],FILTER_VALIDATE_EMAIL))exit("Email disabled. Set MAIL_ENABLED=1 and a valid MAIL_FROM after configuring the server mail transport.\n");
// MySQL named lock prevents concurrent cron workers from sending the same queue.
if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'&&(int)q("SELECT GET_LOCK('cnr_mail_worker',0)")->fetchColumn()!==1)exit("Worker already running.\n");
examRunNotifications();
foreach(all("SELECT id FROM outbox WHERE status IN ('queued','failed') AND attempts<5 ORDER BY id LIMIT 50")as $item){
 db()->beginTransaction();try{
  // Recheck under a row lock: an administrator may have cancelled this message since the batch was loaded.
  // Same lock order as deletion: examination first, then outbox.
  if(examReady()){$link=one('SELECT window_id FROM exam_mail_windows WHERE outbox_id=?',[$item['id']]);if($link){q('UPDATE exam_windows SET id=id WHERE id=?',[$link['window_id']]);$window=one('SELECT deleted_at FROM exam_windows WHERE id=?',[$link['window_id']]);if(!$window||$window['deleted_at']){q("UPDATE outbox SET status='superseded' WHERE id=? AND status IN ('queued','failed')",[$item['id']]);db()->commit();continue;}}}
  $candidate=one('SELECT * FROM outbox WHERE id=?',[$item['id']]);
  if($candidate&&!examNoticeAllowed($candidate)){q("UPDATE outbox SET status='superseded' WHERE id=? AND status IN ('queued','failed')",[$item['id']]);db()->commit();continue;}
  $m=one('SELECT * FROM outbox WHERE id=?'.(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[$item['id']]);
  if(!$m||!in_array($m['status'],['queued','failed'],true)||(int)$m['attempts']>=5){db()->commit();continue;}
  $mailContent=queuedMailContent($m);
  $ok=mail($m['recipient'],'=?UTF-8?B?'.base64_encode($m['subject']).'?=',$mailContent[0],$mailContent[1]);
  q('UPDATE outbox SET status=?,attempts=attempts+1,sent_at=? WHERE id=?',[$ok?'sent':'failed',$ok?now():null,$m['id']]);db()->commit();
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();q("UPDATE outbox SET status='failed',attempts=attempts+1 WHERE id=? AND status IN ('queued','failed')",[$item['id']]);error_log('Mail queue item '.$item['id'].' failed.');}
}
echo "Queue processed. 'Sent' means accepted by the server mail transport.\n";
