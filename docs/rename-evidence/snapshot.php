<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=$app['db']->connection();
$db->beginTransaction(); $db->statement('SET TRANSACTION READ ONLY');
$tables=['users','roles','permissions','model_has_roles','role_has_permissions','user_sso_identities','aaud_auth_catalog_states','aaud_auth_catalog_operations','aaud_auth_access_states','aaud_auth_access_targets','aaud_auth_access_operations','migrations'];
$out=[];
foreach($tables as $t){$out[$t]=$db->selectOne("select count(*) as count, md5(coalesce(string_agg(md5(row_to_json(t)::text), '' order by md5(row_to_json(t)::text)), '')) as fingerprint from \"$t\" t");}
$out['verified_users']=$db->select("select u.id,u.name,u.is_active,r.name as role,i.issuer,i.subject from users u left join model_has_roles m on m.model_id=u.id and m.model_type='App\\Models\\User' left join roles r on r.id=m.role_id left join user_sso_identities i on i.user_id=u.id where lower(u.name) like '%ana%duarte%' or lower(u.name) like '%ricardo%vargas%' order by u.id,r.id");
$db->rollBack();echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
