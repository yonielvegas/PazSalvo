<?php
require __DIR__.'/../../vendor/autoload.php';$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach([15,16] as $id){$u=App\Models\User::findOrFail($id);$i=$u->ssoIdentity; if(!$i)$i=App\Models\UserSsoIdentity::where('user_id',$id)->firstOrFail();
$snapshot=App\Integrations\AaudOauth\Access\AccessSnapshot::fromArray(['issuer'=>$i->issuer,'subject'=>$i->subject,'application_user_id'=>(string)$u->id,'is_active'=>(bool)$u->is_active,'roles'=>$u->roles()->pluck('code')->all()]);
try{$remote=app(App\Integrations\AaudOauth\Http\AaudOauthClient::class)->getUserAccess($snapshot);echo json_encode(['user'=>$u->name,'local_role'=>$u->roles()->pluck('name')->all(),'remote'=>$remote],JSON_UNESCAPED_UNICODE).PHP_EOL;}catch(Throwable $e){echo json_encode(['user'=>$u->name,'error_class'=>get_class($e),'reason'=>property_exists($e,'reason')?$e->reason:null]).PHP_EOL;exit(1);}}
