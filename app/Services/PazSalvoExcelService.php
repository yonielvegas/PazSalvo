<?php

namespace App\Services;

use App\Exceptions\ExcelLookupException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PazSalvoExcelService
{
    public function generate(array $data, string $qrPath): string
    {
        $disk = Storage::disk(config('paz-salvo.disk'));
        foreach ([config('paz-salvo.template_excel'), config('paz-salvo.logo'), config('paz-salvo.signature'), $qrPath] as $required) {
            if (! $disk->exists($required)) {
                throw new ExcelLookupException("Falta un recurso requerido para el certificado: {$required}");
            }
        }

        $relative = trim(config('paz-salvo.output_dir'), '/').'/'.$data['issued_at']->format('Y/m').'/paz_salvo_'.$data['client_number'].'_'.$data['folio'].'.xlsx';
        $disk->makeDirectory(dirname($relative));

        try {
            $book = IOFactory::load($disk->path(config('paz-salvo.template_excel')));
            $book->removeSheetByIndex(0);
            $sheet = new Worksheet($book, 'Certificado');
            $book->addSheet($sheet, 0);
            $book->setActiveSheetIndex(0);
            $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
            foreach (range('A', 'H') as $column) {
                $sheet->getColumnDimension($column)->setWidth(13);
            }

            $this->image($disk->path(config('paz-salvo.logo')), $sheet, 'A1', 72);
            $this->image($disk->path($qrPath), $sheet, 'G1', 96);
            $sheet->mergeCells('B1:F2')->setCellValue('B1', 'Certificado de Paz y Salvo');
            $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(20)->getColor()->setRGB('166534');
            $sheet->mergeCells('B3:F3')->setCellValue('B3', 'Autoridad de Aseo Urbano y Domiciliario');
            $sheet->mergeCells('B4:F4')->setCellValue('B4', 'Folio: '.$data['folio']);
            $sheet->getStyle('B1:F4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension(1)->setRowHeight(34);
            $sheet->getRowDimension(2)->setRowHeight(34);

            $sheet->mergeCells('A6:C6')->setCellValue('A6', 'Fecha de emisión: '.$data['issued_at']->format('d/m/Y'));
            $sheet->mergeCells('D6:E6')->setCellValue('D6', 'Hora: '.$data['issued_at']->format('h:i A'));
            $sheet->mergeCells('F6:H6')->setCellValue('F6', 'Expira: '.$data['expires_at']->format('d/m/Y'));
            $sheet->getStyle('A6:H6')->getFont()->setBold(true);

            $formal = "Por este medio se certifica que el número de cliente {$data['client_number']}, a nombre de {$data['holder_name']}, se encuentra paz y salvo con la Autoridad de Aseo Urbano y Domiciliario por el servicio de recolección de desechos, según los registros consultados al momento de la emisión de este documento.";
            $sheet->mergeCells('A8:H12')->setCellValue('A8', $formal);
            $sheet->getStyle('A8:H12')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_JUSTIFY);

            $rows = [14 => ['Número de cliente / NAC', $data['client_number']], 15 => ['Nombre completo', $data['holder_name']], 16 => ['Dirección completa', $data['full_address']]];
            foreach ($rows as $row => [$label, $value]) {
                $sheet->mergeCells("A{$row}:C{$row}")->setCellValue("A{$row}", $label);
                $sheet->mergeCells("D{$row}:H{$row}")->setCellValue("D{$row}", $value);
                $sheet->getStyle("A{$row}:C{$row}")->getFont()->setBold(true);
                $sheet->getStyle("D{$row}:H{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                $sheet->getStyle("A{$row}:H{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
            }

            $sheet->mergeCells('A19:D19')->setCellValue('A19', 'Elaborado por: '.$data['generated_by_name_snapshot']);
            $sheet->mergeCells('A20:D20')->setCellValue('A20', 'Agencia: '.$data['agency_name_snapshot']);
            $sheet->mergeCells('A23:D23')->setCellValue('A23', 'Autorizado por: '.$data['authorized_by_name']);
            $this->image($disk->path(config('paz-salvo.signature')), $sheet, 'A24', 90);
            $sheet->mergeCells('F19:H28')->setCellValue('F19', 'SELLO');
            $sheet->getStyle('F19:H28')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_MEDIUM);
            $sheet->getStyle('F19:H28')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle('F19:H28')->getFont()->getColor()->setRGB('94A3B8');

            $sheet->mergeCells('A31:H39')->setCellValue('A31', $data['legal_text']);
            $sheet->getStyle('A31:H39')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_JUSTIFY);
            $sheet->getStyle('A31:H39')->getFont()->setBold(true)->setSize(8);
            $sheet->getStyle('A31:H39')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');

            $sheet->getPageSetup()->setPrintArea('A1:H40')->setFitToWidth(1)->setFitToHeight(1);
            $sheet->getPageMargins()->setTop(.3)->setBottom(.3)->setLeft(.3)->setRight(.3);
            $sheet->getPageSetup()->setOrientation('portrait')->setPaperSize(9);
            (new Xlsx($book))->save($disk->path($relative));
            $book->disconnectWorksheets();

            return $relative;
        } catch (\Throwable $e) {
            Log::error('Falló la generación del certificado XLSX.', ['folio' => $data['folio'], 'error' => $e->getMessage()]);
            throw new ExcelLookupException('No se pudo generar el Excel del paz y salvo.', previous: $e);
        }
    }

    private function image(string $path, Worksheet $sheet, string $cell, int $height): void
    {
        $drawing = new Drawing;
        $drawing->setPath($path)->setCoordinates($cell)->setHeight($height)->setWorksheet($sheet);
    }
}
