<?php
function spDelegated(array $u):bool{return !empty($u['active'])&&in_array($u['role'],['daa','exam','hod','pl'],true)&&(bool)(one('SELECT can_delete FROM attendance_rights WHERE user_id=?',[$u['id']])['can_delete']??false);}
function spBrowse(array $u):bool{return saReviewer($u)||spDelegated($u);}
function spScope(array $u,array $r):bool{return !in_array($u['role'],['hod','pl'],true)||($u['department']!==''&&$u['department']===$r['department']);}
function spDeleteAllowed(array $u,array $r):bool{return acAdmin($u)||(spDelegated($u)&&spScope($u,$r))||((int)$r['owner_id']===(int)$u['id']&&(bool)(one('SELECT can_delete FROM attendance_rights WHERE user_id=?',[$u['id']])['can_delete']??false));}
function spModuleList(array $u,array $filters):array{
 $sql="SELECT r.*,u.name AS tutor_name FROM academic_records r JOIN users u ON u.id=r.owner_id WHERE r.kind='attendance'";$args=[];
 if(!spBrowse($u)){$sql.=' AND r.owner_id=?';$args[]=$u['id'];}elseif(in_array($u['role'],['hod','pl'],true)){$sql.=' AND r.department=?';$args[]=$u['department'];}
 foreach(['department','programme'] as $key)if(!empty($filters[$key])){$sql.=' AND r.'.$key.'=?';$args[]=$filters[$key];}
 if(trim($filters['search']??'')!==''){$sql.=' AND (u.name LIKE ? OR r.module_code LIKE ?)';$args[]='%'.trim($filters['search']).'%';$args[]='%'.trim($filters['search']).'%';}
 return all($sql.' ORDER BY r.updated_at DESC,r.id DESC',$args);
}
