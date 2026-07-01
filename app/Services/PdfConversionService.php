<?php

namespace App\Services;

use App\Exceptions\PdfConversionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class PdfConversionService
{
    public function convertXlsxToPdf(string $xlsxPath): string
    {
        $disk = Storage::disk(config('paz-salvo.disk'));
        if (! $disk->exists($xlsxPath)) {
            throw new PdfConversionException('No se encontró el archivo XLSX para convertir.');
        }

        try {
            $outputDir = dirname($disk->path($xlsxPath));
            $profile = sys_get_temp_dir().'/libreoffice-'.bin2hex(random_bytes(8));
            $process = new Process([
                config('paz-salvo.libreoffice_binary'),
                '-env:UserInstallation=file://'.$profile,
                '--headless', '--convert-to', 'pdf', '--outdir', $outputDir, $disk->path($xlsxPath),
            ]);
            $process->setTimeout(config('paz-salvo.conversion_timeout'));
            $process->run();
            $pdfPath = preg_replace('/\.xlsx$/i', '.pdf', $xlsxPath);

            if (! $process->isSuccessful() || ! $disk->exists($pdfPath) || $disk->size($pdfPath) === 0) {
                throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'LibreOffice no produjo un PDF válido.');
            }

            return $pdfPath;
        } catch (\Throwable $exception) {
            Log::error('Falló la conversión del paz y salvo a PDF.', [
                'xlsx_path' => $xlsxPath,
                'exception' => $exception->getMessage(),
            ]);

            throw new PdfConversionException('No se pudo convertir el documento a PDF.', previous: $exception);
        }
    }
}
