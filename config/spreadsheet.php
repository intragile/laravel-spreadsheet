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

        // Extra header names per field key, used by the column auto-mapper next to the field's key,
        // labels and own synonyms. Shape: field key => [locale => list of names]; the locale '*'
        // applies whatever the configured locales are. Only the locales listed in `locales` above
        // are consulted.
        'synonyms' => [
            'email' => [
                'en' => ['e-mail', 'email address', 'mail', 'e mail address'],
                'hu' => ['e-mail cím', 'email cím', 'e-mail cim'],
                'de' => ['e-mail-adresse', 'email adresse', 'mailadresse'],
                'es' => ['correo electrónico', 'correo', 'dirección de correo'],
            ],
            'phone' => [
                'en' => ['telephone', 'phone number', 'mobile', 'tel', 'telephone number'],
                'hu' => ['telefon', 'telefonszám', 'mobil', 'mobilszám', 'tel'],
                'de' => ['telefon', 'telefonnummer', 'handy', 'mobilnummer'],
                'es' => ['teléfono', 'telefono', 'móvil', 'movil', 'número de teléfono'],
            ],
            'name' => [
                'en' => ['full name', 'contact name', 'customer name', 'partner name'],
                'hu' => ['név', 'teljes név', 'partner neve', 'ügyfél neve'],
                'de' => ['name', 'vollständiger name', 'kundenname'],
                'es' => ['nombre', 'nombre completo', 'nombre del cliente'],
            ],
            'first_name' => [
                'en' => ['first name', 'given name', 'forename'],
                'hu' => ['keresztnév', 'utónév'],
                'de' => ['vorname'],
                'es' => ['nombre de pila', 'primer nombre'],
            ],
            'last_name' => [
                'en' => ['last name', 'surname', 'family name'],
                'hu' => ['vezetéknév', 'családnév'],
                'de' => ['nachname', 'familienname'],
                'es' => ['apellido', 'apellidos'],
            ],
            'company' => [
                'en' => ['company name', 'organization', 'organisation', 'employer', 'business'],
                'hu' => ['cég', 'cégnév', 'cég neve', 'vállalkozás', 'szervezet'],
                'de' => ['firma', 'unternehmen', 'firmenname'],
                'es' => ['empresa', 'compañía', 'razón social', 'nombre de la empresa'],
            ],
            'tax_number' => [
                'en' => ['tax id', 'tax no', 'vat number', 'vat id', 'tax identification number'],
                'hu' => ['adószám', 'adoszam', 'adóazonosító', 'adóazonosító jel', 'áfa szám'],
                'de' => ['steuernummer', 'umsatzsteuer-id', 'ust-idnr', 'steuer-id'],
                'es' => ['nif', 'cif', 'número de identificación fiscal', 'identificación fiscal'],
            ],
            'address' => [
                'en' => ['street address', 'street', 'address line 1', 'postal address'],
                'hu' => ['cím', 'levelezési cím', 'utca', 'utca, házszám', 'lakcím', 'székhely'],
                'de' => ['adresse', 'straße', 'strasse', 'anschrift'],
                'es' => ['dirección', 'direccion', 'calle', 'domicilio'],
            ],
            'city' => [
                'en' => ['town', 'locality', 'municipality'],
                'hu' => ['város', 'település', 'telepules', 'helység'],
                'de' => ['stadt', 'ort', 'wohnort'],
                'es' => ['ciudad', 'localidad', 'población', 'municipio'],
            ],
            'zip' => [
                'en' => ['zip code', 'postal code', 'postcode', 'post code', 'zipcode'],
                'hu' => ['irányítószám', 'iranyitoszam', 'ir.szám', 'irsz'],
                'de' => ['postleitzahl', 'plz'],
                'es' => ['código postal', 'codigo postal', 'cp'],
            ],
            'country' => [
                'en' => ['nation', 'country name', 'country code'],
                'hu' => ['ország', 'országkód', 'orszag'],
                'de' => ['land', 'ländercode'],
                'es' => ['país', 'pais', 'código de país'],
            ],
            'website' => [
                'en' => ['web site', 'url', 'homepage', 'web address'],
                'hu' => ['weboldal', 'honlap', 'web cím', 'webcím'],
                'de' => ['webseite', 'website-url', 'internetseite', 'homepage'],
                'es' => ['sitio web', 'página web', 'pagina web'],
            ],
            'domain' => [
                'en' => ['domain name', 'hostname'],
                'hu' => ['domain név', 'domainnév'],
                'de' => ['domainname', 'domäne', 'domain-name'],
                'es' => ['nombre de dominio', 'dominio'],
            ],
            'currency' => [
                'en' => ['currency code', 'ccy'],
                'hu' => ['pénznem', 'deviza', 'valuta'],
                'de' => ['währung', 'währungscode'],
                'es' => ['moneda', 'divisa', 'código de moneda'],
            ],
            'language' => [
                'en' => ['lang', 'language code', 'locale', 'preferred language'],
                'hu' => ['nyelv', 'nyelvkód', 'kommunikációs nyelv'],
                'de' => ['sprache', 'sprachcode', 'bevorzugte sprache'],
                'es' => ['idioma', 'lengua', 'código de idioma'],
            ],
            'date' => [
                'en' => ['date of entry', 'created at'],
                'hu' => ['dátum', 'datum', 'időpont', 'nap'],
                'de' => ['datum', 'erstellt am'],
                'es' => ['fecha', 'día', 'fecha de creación'],
            ],
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

    'failed_rows' => [
        // Extra header texts, besides the shipped translations, that mark the status and message
        // columns of a failed-rows file so they do not change a file's header fingerprint.
        'headers' => [],
    ],

    'templates' => [
        // Mapping template store; null disables persistence.
        'store' => null,

        // Table used by the database store (publish the migration with the `spreadsheet-migrations` tag).
        'table' => 'spreadsheet_mapping_templates',
    ],
];
