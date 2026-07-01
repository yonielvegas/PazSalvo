<?php

return [
    'disk' => env('PAZ_SALVO_DISK', 'local'),
    'clients_excel' => env('PAZ_SALVO_CLIENTS_EXCEL', 'templates/clientes.xlsx'),
    'template_excel' => env('PAZ_SALVO_TEMPLATE_EXCEL', 'templates/plantilla_paz_y_salvo.xlsx'),
    'output_dir' => env('PAZ_SALVO_OUTPUT_DIR', 'generated/paz-salvos'),
    'logo' => env('PAZ_SALVO_LOGO', 'templates/assets/AAUD.jpg'),
    'signature' => env('PAZ_SALVO_SIGNATURE', 'templates/assets/Firma.jpeg'),
    'query_ttl_minutes' => (int) env('PAZ_SALVO_QUERY_TTL', 15),
    'authorized_by' => env('PAZ_SALVO_AUTHORIZED_BY', 'Vielsa Vergara'),
    'legal_text' => 'BASADO EN EL ARTÍCULO 79 DE LA LEY NO. 276 DE 30 DE DICIEMBRE DE 2021, QUE INDICA LO SIGUIENTE: ARTÍCULO 79. EL REGISTRO PÚBLICO NO PRACTICARÁ NINGUNA INSCRIPCIÓN RELATIVA A BIENES INMUEBLES MIENTRAS NO SE COMPRUEBE QUE ESTÁN PAZ Y SALVO CON LA AUTORIDAD DE ASEO URBANO Y DOMICILIARIO O EN LA ENTIDAD COMPETENTE, PARA REALIZAR LOS COBROS DE LA TASA DE GESTIÓN INTEGRAL DE RESIDUOS POR EL SERVICIO DE RECOLECCIÓN QUE RIGE A PARTIR DEL 01 DE JULIO DE 2022',
    'libreoffice_binary' => env('LIBREOFFICE_BINARY', 'libreoffice'),
    'conversion_timeout' => (int) env('LIBREOFFICE_TIMEOUT', 60),
];
