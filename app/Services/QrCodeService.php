<?php

namespace App\Services;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Storage;

class QrCodeService
{
    public function generate(string $url, string $folio): string
    {
        $path = trim(config('paz-salvo.output_dir'), '/').'/'.now()->format('Y/m').'/qr_'.$folio.'_'.bin2hex(random_bytes(6)).'.png';
        $disk = Storage::disk(config('paz-salvo.disk'));
        $disk->makeDirectory(dirname($path));

        $result = (new Builder(
            writer: new PngWriter, data: $url, encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High, size: 300, margin: 12,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        ))->build();
        $disk->put($path, $result->getString());

        return $path;
    }
}
