<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

$spreadsheet = new PhpOffice\PhpSpreadsheet\Spreadsheet;
$spreadsheet->getActiveSheet()->fromArray([['NAC', 'Nombre completo', 'Distrito', 'Corregimiento', 'Direccion'], ['123', 'Cliente E2E', 'Panamá', 'Bella Vista', 'Prueba']]);
(new PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save(sys_get_temp_dir().'/clients-e2e.xlsx');
$spreadsheet->disconnectWorksheets();
