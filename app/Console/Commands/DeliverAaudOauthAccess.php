<?php

namespace App\Console\Commands;

use App\Integrations\AaudOauth\Access\AccessDelivery;
use Illuminate\Console\Command;

final class DeliverAaudOauthAccess extends Command
{
    protected $signature = 'aaud-oauth:deliver';

    protected $description = 'Recupera entregas ya autorizadas de los Outbox AAUD OAUTH (scheduler).';

    public function handle(AccessDelivery $delivery): int
    {
        $delivery->recover();

        return self::SUCCESS;
    }
}
