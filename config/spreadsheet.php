<?php

declare(strict_types=1);

return [
    'export' => [
        // Rows fetched per keyset page.
        'chunk_size' => 1000,

        // Header row style: bold, italic, wrap, fill (hex), font_color (hex), align, border (bool|hex).
        'header_style' => ['bold' => true, 'fill' => 'DDDDDD'],

        'csv' => [
            'delimiter' => ',',
            'bom' => false,
            'escape_formulas' => true,
        ],
    ],

    'import' => [
        // Locales whose names are accepted for countries, languages and enum labels.
        'locales' => ['en'],

        // Unambiguous date layouts, tried in order; each is also tried with a time of day.
        // Slash dates (d/m/Y or m/d/Y) are handled separately and need a column layout when ambiguous.
        'date_formats' => ['Y-m-d', 'Y.m.d.', 'Y.m.d', 'Y/m/d', 'd.m.Y.', 'd.m.Y', 'd-m-Y'],

        // Words read as true and false, compared ignoring case and accents.
        'boolean' => [
            'true' => ['1', 'igen', 'yes', 'ja', 'sí', 'si', 'x', 'true', 'aktív', 'active', 'igaz', 'wahr', 'verdadero'],
            'false' => ['0', 'nem', 'no', 'nein', 'false', 'inaktív', 'inactive', 'hamis', 'falsch', 'falso'],
        ],

        // Currency symbols and the ISO 4217 code they stand for.
        'currency_symbols' => ['Ft' => 'HUF', '€' => 'EUR', '$' => 'USD', '£' => 'GBP'],

        // Extra names accepted for a country (ISO 3166-1 alpha-2), currency (ISO 4217) or language (ISO 639-1).
        'aliases' => [
            'country' => [
                'usa' => 'US',
                'united states of america' => 'US',
                'uk' => 'GB',
                'great britain' => 'GB',
                'england' => 'GB',
                'czech republic' => 'CZ',
                'holland' => 'NL',
                'turkey' => 'TR',
                'bosnia and herzegovina' => 'BA',
                'ivory coast' => 'CI',
                'burma' => 'MM',
                'swaziland' => 'SZ',
                'south korea' => 'KR',
                'republic of korea' => 'KR',
                'north korea' => 'KP',
                'russia' => 'RU',
                'vietnam' => 'VN',
                'laos' => 'LA',
                'syria' => 'SY',
                'iran' => 'IR',
                'moldova' => 'MD',
                'macedonia' => 'MK',
                'north macedonia' => 'MK',
                'cape verde' => 'CV',
                'vatican' => 'VA',
                'palestine' => 'PS',
                'dr congo' => 'CD',
                'democratic republic of the congo' => 'CD',
                'republic of the congo' => 'CG',
            ],
            'currency' => [
                'forint' => 'HUF',
                'euro' => 'EUR',
                'us dollar' => 'USD',
                'pound sterling' => 'GBP',
            ],
            'language' => [],
        ],
    ],

    'templates' => [
        // Mapping template store; null disables persistence.
        'store' => null,
    ],
];
