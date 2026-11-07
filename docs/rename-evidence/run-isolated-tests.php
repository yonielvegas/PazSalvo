<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$name='paz_oauth_rename_20261029_testing';$db=$app['db']->connection();
if (!$db->selectOne('select 1 as present from pg_database where datname=?',[$name])) $db->statement('CREATE DATABASE '.$name);
$c=config('database.connections.pgsql');
$env=array_merge(getenv(),Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__,2).'/.env.testing')),['BCRYPT_ROUNDS'=>'4','AAUD_OAUTH_ENABLED'=>'false','AAUD_OAUTH_AUTOMATIC'=>'false','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','APP_CONFIG_CACHE'=>__DIR__.'/tmp/config.php','APP_SERVICES_CACHE'=>__DIR__.'/tmp/services.php','APP_PACKAGES_CACHE'=>__DIR__.'/tmp/packages.php','APP_ROUTES_CACHE'=>__DIR__.'/tmp/routes.php','VIEW_COMPILED_PATH'=>__DIR__.'/tmp/views']);foreach(['APP_ENV'=>'testing','DB_URL'=>'','DB_CONNECTION'=>'pgsql','DB_DATABASE'=>$name,'DB_HOST'=>$c['host'],'DB_PORT'=>(string)$c['port'],'DB_USERNAME'=>$c['username'],'DB_PASSWORD'=>$c['password'],'TMPDIR'=>__DIR__.'/tmp'] as $k=>$v)$env[$k]=$v;
@mkdir(__DIR__.'/tmp/views',0700,true);
$run=function(array $cmd)use($env){$p=proc_open($cmd,[STDIN,STDOUT,STDERR],$pipes,dirname(__DIR__,2),$env);return proc_close($p);};
if($run([PHP_BINARY,'artisan','migrate','--force','--no-interaction'])!==0)exit(1);
exit($run([PHP_BINARY,'vendor/bin/phpunit','--bootstrap','docs/rename-evidence/test-bootstrap.php','--exclude-group','destructive-migration',$argv[1]??'--testdox']));
