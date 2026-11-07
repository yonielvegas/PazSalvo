<?php
require __DIR__.'/../../vendor/autoload.php';$a=require __DIR__.'/../../bootstrap/app.php';$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$db=$a['db']->connection();$db->beginTransaction();$db->statement('SET TRANSACTION READ ONLY');
$out=[];
foreach(['aaud_auth_access_operations','aaud_auth_catalog_operations'] as $t)$out[$t]=$db->select("select status,count(*) from $t group by status order by status");
$out['delivery']=$db->select('select delivery_error,count(*) from aaud_auth_access_targets group by delivery_error');
$out['unresolved']=$db->selectOne("select count(*) as count from aaud_auth_access_operations where status not in ('confirmed','cancelled')");
$out['identity_duplicates']=$db->selectOne('select count(*) as count from (select issuer,subject from user_sso_identities group by issuer,subject having count(*)>1) t');
$out['multiple_roles']=$db->selectOne("select count(*) as count from (select model_id from model_has_roles where model_type='App\\Models\\User' group by model_id having count(*)>1) t");
$out['migration_count']=$db->table('migrations')->count();
$db->rollBack();echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
