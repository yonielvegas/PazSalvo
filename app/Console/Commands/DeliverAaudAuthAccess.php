<?php

namespace App\Console\Commands;

use App\Integrations\AaudAuth\Access\AccessDelivery;
use Illuminate\Console\Command;

final class DeliverAaudAuthAccess extends Command
{
    protected $signature = 'aaud-auth:deliver';

    protected $description = 'Recupera entregas ya autorizadas de los Outbox AAUD AUTH (scheduler).';

    public function handle(AccessDelivery $delivery): int
    {
        $delivery->recover();

        return self::SUCCESS;
    }
}
