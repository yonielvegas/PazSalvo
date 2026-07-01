<?php

namespace App\Console\Commands;

use App\Models\PazSalvo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ReconcilePazSalvoProcessing extends Command
{
    protected $signature = 'paz-salvo:reconcile-processing {--minutes=30 : Antigüedad mínima}';

    protected $description = 'Marca como error las emisiones processing abandonadas y limpia archivos parciales';

    public function handle(): int
    {
        $count = 0;
        PazSalvo::where('status', PazSalvo::PROCESSING)->where('created_at', '<', now()->subMinutes((int) $this->option('minutes')))
            ->eachById(function (PazSalvo $document) use (&$count) {
                Storage::disk(config('paz-salvo.disk'))->delete(array_filter([$document->qr_path, $document->xlsx_path, $document->pdf_path]));
                $document->update(['status' => PazSalvo::ERROR, 'qr_path' => null, 'xlsx_path' => null, 'pdf_path' => null, 'generation_error' => 'Emisión interrumpida antes de finalizar.']);
                $count++;
            });
        $this->info("{$count} emisiones reconciliadas.");

        return self::SUCCESS;
    }
}
