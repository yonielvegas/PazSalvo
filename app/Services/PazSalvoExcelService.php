<?php

namespace App\Services;

use App\Exceptions\ExcelLookupException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PazSalvoExcelService
{
    public function generate(array $data, string $qrPath): string
    {
        $disk = Storage::disk(config('paz-salvo.disk'));
        foreach ([config('paz-salvo.template_excel'), config('paz-salvo.logo'), $data['authorized_signature_path'], $qrPath] as $required) {
            if (! $disk->exists($required)) {
                throw new ExcelLookupException("Falta un recurso requerido para el certificado: {$required}");
            }
        }

        $relative = trim(config('paz-salvo.output_dir'), '/').'/'.$data['issued_at']->format('Y/m').'/paz_salvo_'.$data['client_number'].'_'.$data['folio'].'.xlsx';
        $disk->makeDirectory(dirname($relative));

        $selloPath = null;
        $grayLogoPath = null;

        try {
            $book = IOFactory::load($disk->path(config('paz-salvo.template_excel')));
            $book->removeSheetByIndex(0);
            $sheet = new Worksheet($book, 'Certificado');
            $book->addSheet($sheet, 0);
            $book->setActiveSheetIndex(0);
            $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(12);

            foreach (range('A', 'F') as $column) {
                $sheet->getColumnDimension($column)->setWidth(11);
            }
            foreach (range('G', 'J') as $column) {
                $sheet->getColumnDimension($column)->setWidth(14);
            }

            // ── Header: logo grayscale left, QR right, title centered ──
            $originalLogoPath = $disk->path(config('paz-salvo.logo'));
            $grayLogoPath = $this->grayscaleImage($originalLogoPath);

            $this->image($grayLogoPath ?? $originalLogoPath, $sheet, 'A1', 120);

            $qrDrawing = new Drawing;
            $qrDrawing->setPath($disk->path($qrPath));
            $qrDrawing->setCoordinates('I1');
            $qrDrawing->setOffsetX(10);
            $qrDrawing->setOffsetY(14);
            $qrDrawing->setHeight(120);
            $qrDrawing->setWorksheet($sheet);

            $sheet->mergeCells('B2:I2')->setCellValue('B2', 'Certificado de Paz y Salvo');
            $sheet->getStyle('B2')->getFont()->setBold(true)->setSize(24)->getColor()->setRGB('000000');
            $sheet->mergeCells('B3:I3')->setCellValue('B3', 'Autoridad de Aseo Urbano y Domiciliario');
            $sheet->mergeCells('B4:I4')->setCellValue('B4', 'Folio: '.$data['folio']);
            $sheet->getStyle('B2:I4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension(1)->setRowHeight(80);
            $sheet->getRowDimension(2)->setRowHeight(42);
            $sheet->getRowDimension(3)->setRowHeight(20);
            $sheet->getRowDimension(4)->setRowHeight(18);

            // ── Date row ──
            $sheet->mergeCells('A6:C6')->setCellValue('A6', 'Fecha de emisión: '.$data['issued_at']->format('d/m/Y'));
            $sheet->mergeCells('D6:F6')->setCellValue('D6', 'Hora: '.$data['issued_at']->format('h:i A'));
            $sheet->mergeCells('G6:J6')->setCellValue('G6', 'Expira: '.$data['expires_at']->format('d/m/Y'));
            $sheet->getStyle('A6:J6')->getFont()->setBold(true);
            $sheet->getRowDimension(6)->setRowHeight(22);

            // ── RichText paragraph ──
            $richText = new RichText;
            $certifica = $richText->createTextRun('CERTIFICA');
            $certifica->getFont()->setBold(true)->setSize(15);

            $middle1 = $richText->createTextRun(' que el cliente con número de cliente ');
            $middle1->getFont()->setSize(15);

            $nac = $richText->createTextRun($data['client_number']);
            $nac->getFont()->setBold(true)->setSize(15);

            $middle2 = $richText->createTextRun(', a nombre de ');
            $middle2->getFont()->setSize(15);

            $name = $richText->createTextRun($data['holder_name']);
            $name->getFont()->setBold(true)->setSize(15);

            $middle3 = $richText->createTextRun(', se encuentra ');
            $middle3->getFont()->setSize(15);

            $pys = $richText->createTextRun('PAZ Y SALVO');
            $pys->getFont()->setBold(true)->setSize(15);

            $middle4 = $richText->createTextRun(' con la Autoridad de Aseo Urbano y Domiciliario por el servicio de recolección de desechos en el distrito de San Miguelito, según los registros consultados al momento de la emisión de este documento.');
            $middle4->getFont()->setSize(15);

            $sheet->mergeCells('A8:J12')->setCellValue('A8', $richText);
            $sheet->getStyle('A8:J12')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_JUSTIFY);
            $sheet->getRowDimension(8)->setRowHeight(55);
            $sheet->getRowDimension(9)->setRowHeight(10);
            $sheet->getRowDimension(10)->setRowHeight(10);
            $sheet->getRowDimension(11)->setRowHeight(10);
            $sheet->getRowDimension(12)->setRowHeight(10);

            // ── Client info table ──
            $rows = [14 => ['Número de cliente / NAC', $data['client_number']], 15 => ['Nombre completo', $data['holder_name']], 16 => ['Dirección completa', $data['full_address']]];
            foreach ($rows as $row => [$label, $value]) {
                $sheet->mergeCells("A{$row}:C{$row}")->setCellValue("A{$row}", $label);
                $sheet->mergeCells("D{$row}:J{$row}")->setCellValue("D{$row}", $value);
                $sheet->getStyle("A{$row}:C{$row}")->getFont()->setBold(true);
                $sheet->getStyle("D{$row}:J{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                $sheet->getStyle("A{$row}:J{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D4D4D4');
                $sheet->getRowDimension($row)->setRowHeight(26);
            }

            // ── Info rows ──
            $sheet->mergeCells('A18:E18')->setCellValue('A18', 'Agencia: '.$data['agency_name']);
            $sheet->mergeCells('A19:E19')->setCellValue('A19', 'Elaborado por: '.$data['generated_by_name']);
            $sheet->getRowDimension(18)->setRowHeight(22);
            $sheet->getRowDimension(19)->setRowHeight(22);

            // ── Manual signature area for Elaborado por (space above the line) ──
            $sheet->mergeCells('A20:E20');
            $sheet->getStyle('A20:E20')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('999999');
            $sheet->getRowDimension(20)->setRowHeight(42);

            $sheet->mergeCells('A21:E21')->setCellValue('A21', 'Firma');
            $sheet->getStyle('A21:E21')->getFont()->setSize(9)->setItalic(true)->getColor()->setRGB('999999');
            $sheet->getRowDimension(21)->setRowHeight(14);

            $sheet->getRowDimension(22)->setRowHeight(12);

            // ── Authorized signature block ──
            $sheet->mergeCells('A24:F24')->setCellValue('A24', 'Autorizado por: '.$data['authorized_by_name']);
            $sheet->getStyle('A24:F24')->getFont()->setBold(true);
            $sheet->getRowDimension(24)->setRowHeight(26);

            $sheet->getRowDimension(25)->setRowHeight(10);

            $this->image($disk->path($data['authorized_signature_path']), $sheet, 'A26', 60);
            $sheet->getRowDimension(26)->setRowHeight(50);

            $sheet->getRowDimension(27)->setRowHeight(8);
            $sheet->getRowDimension(28)->setRowHeight(8);

            // ── SELLO: square PNG image aligned with signature block ──
            $selloSize = 253;
            $selloPath = tempnam(sys_get_temp_dir(), 'sello_').'.png';
            $this->createSelloImage($selloPath, $selloSize);
            $this->image($selloPath, $sheet, 'H18', $selloSize);

            // ── Legal text ──
            $legalStart = 30;
            $legalEnd = 35;
            $legalRange = "A{$legalStart}:J{$legalEnd}";
            $sheet->mergeCells($legalRange)->setCellValue("A{$legalStart}", $data['legal_text']);
            $sheet->getStyle($legalRange)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_JUSTIFY);
            $sheet->getStyle($legalRange)->getFont()->setBold(true)->setSize(9);
            $sheet->getStyle($legalRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F5F5F5');
            $sheet->getStyle($legalRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D4D4D4');
            foreach (range($legalStart, $legalEnd) as $row) {
                $sheet->getRowDimension($row)->setRowHeight(20);
            }

            // ── Page setup: US Letter 8.5x11, portrait, single page ──
            $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_LETTER);
            $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
            $sheet->getPageSetup()->setPrintArea("A1:J{$legalEnd}")->setFitToWidth(1)->setFitToHeight(1);
            $sheet->getPageMargins()->setTop(.35)->setBottom(.35)->setLeft(.3)->setRight(.3);
            (new Xlsx($book))->save($disk->path($relative));
            $book->disconnectWorksheets();

            return $relative;
        } catch (\Throwable $e) {
            Log::error('Falló la generación del certificado XLSX.', ['folio' => $data['folio'], 'error' => $e->getMessage()]);
            throw new ExcelLookupException('No se pudo generar el Excel del paz y salvo.', previous: $e);
        } finally {
            foreach ([$selloPath, $grayLogoPath] as $p) {
                if ($p && file_exists($p)) {
                    unlink($p);
                }
            }
        }
    }

    private function image(string $path, Worksheet $sheet, string $cell, int $height): void
    {
        $drawing = new Drawing;
        $drawing->setPath($path)->setCoordinates($cell)->setHeight($height)->setWorksheet($sheet);
    }

    private function createSelloImage(string $path, int $size = 150): void
    {
        $img = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $white);

        imagerectangle($img, 0, 0, $size - 1, $size - 1, $black);
        imagerectangle($img, 2, 2, $size - 3, $size - 3, $black);

        $text = 'SELLO';
        $font = 5;
        $tx = (int) (($size - strlen($text) * imagefontwidth($font)) / 2);
        $ty = (int) (($size - imagefontheight($font)) / 2);
        imagestring($img, $font, $tx, $ty, $text, $black);

        imagepng($img, $path);
        imagedestroy($img);
    }

    private function grayscaleImage(string $source): ?string
    {
        try {
            $contents = file_get_contents($source);
            if ($contents === false) {
                return null;
            }
            $srcImg = imagecreatefromstring($contents);
            if ($srcImg === false) {
                return null;
            }
            imagefilter($srcImg, IMG_FILTER_GRAYSCALE);
            $tmp = tempnam(sys_get_temp_dir(), 'logo_bw_').'.png';
            imagepng($srcImg, $tmp);
            imagedestroy($srcImg);

            return $tmp;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
