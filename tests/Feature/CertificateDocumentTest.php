<?php

namespace Tests\Feature;

use App\Services\PazSalvoExcelService;
use App\Services\PdfConversionService;
use App\Services\QrCodeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Tests\TestCase;

class CertificateDocumentTest extends TestCase
{
    public function test_real_xlsx_and_pdf_are_generated_with_private_assets(): void
    {
        $issued = Carbon::parse('2026-07-01 14:45', 'America/Panama');
        $paths = [];
        try {
            $paths[] = $qr = app(QrCodeService::class)->generate('http://localhost/verificar/00000000-0000-4000-8000-000000000000', 'TEST-000001');
            $paths[] = $xlsx = app(PazSalvoExcelService::class)->generate([
                'folio' => 'CC-000001-2026', 'client_number' => '34787', 'holder_name' => 'CLIENTE DE PRUEBA',
                'full_address' => 'PANAMÁ - CALLE DE PRUEBA', 'issued_at' => $issued, 'expires_at' => $issued->copy()->addDays(30),
                'generated_by_name' => 'Usuario de Prueba', 'agency_name' => 'Via Brasil',
                'authorized_by_name' => 'Autorizado Via Brasil', 'signature_path' => config('paz-salvo.signature'),
                'legal_text' => config('paz-salvo.legal_text'),
            ], $qr);
            $paths[] = $pdf = app(PdfConversionService::class)->convertXlsxToPdf($xlsx);
            $disk = Storage::disk(config('paz-salvo.disk'));
            $this->assertTrue($disk->exists($xlsx));
            $this->assertTrue($disk->exists($pdf));
            $book = IOFactory::load($disk->path($xlsx));
            $sheet = $book->getActiveSheet();
            $logo = collect($sheet->getDrawingCollection())->first(fn ($drawing) => $drawing->getCoordinates() === 'A1');
            $this->assertNotNull($logo);
            $this->assertLessThanOrEqual(72, $logo->getHeight());
            $this->assertSame(Alignment::HORIZONTAL_LEFT, $sheet->getStyle('D14:H14')->getAlignment()->getHorizontal());
            $book->disconnectWorksheets();
            $this->assertGreaterThan(5000, $disk->size($xlsx));
            $this->assertGreaterThan(1000, $disk->size($pdf));
            $this->assertSame('%PDF', substr($disk->get($pdf), 0, 4));
        } finally {
            Storage::disk(config('paz-salvo.disk'))->delete($paths);
        }
    }
}
