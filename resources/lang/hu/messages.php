<?php

declare(strict_types=1);

return [
    'invalid_date' => '„:value” nem érvényes dátum.',
    'ambiguous_date' => '„:value” lehet nap/hónap és hónap/nap is; válassza ki az oszlop dátumformátumát.',
    'invalid_number' => '„:value” nem érvényes szám.',
    'invalid_integer' => '„:value” nem érvényes egész szám.',
    'invalid_boolean' => '„:value” nem felismerhető igen/nem érték.',
    'unknown_value' => '„:value” nem ismert érték.',
    'unknown_option' => '„:value” nem megengedett érték, a lehetőségek: :options.',
    'invalid_domain' => '„:value” nem érvényes domainnév.',
    'currency_from_symbol' => 'A(z) „:value” pénznem értéke :code lett.',
    // Variants without the cell value, used for sensitive fields.
    'redacted' => [
        'invalid_date' => 'Nem érvényes dátum.',
        'ambiguous_date' => 'Lehet nap/hónap és hónap/nap is; válassza ki az oszlop dátumformátumát.',
        'invalid_number' => 'Nem érvényes szám.',
        'invalid_integer' => 'Nem érvényes egész szám.',
        'invalid_boolean' => 'Nem felismerhető igen/nem érték.',
        'unknown_value' => 'Nem ismert érték.',
        'unknown_option' => 'Nem megengedett érték, a lehetőségek: :options.',
        'invalid_domain' => 'Nem érvényes domainnév.',
        'currency_from_symbol' => 'A pénznemjel értéke :code lett.',
    ],
    'duplicate_in_file' => 'A(z) :row. sor ismétlése.',
    'possible_duplicate_in_file' => 'Valószínűleg azonos a(z) :row. sorral; importálás előtt ellenőrizze.',
    'possible_duplicate' => 'Valószínűleg azonos egy meglévő rekorddal (#:id); importálás előtt ellenőrizze.',
    'matches_trashed' => 'Egy törölt rekorddal egyezik.',
    'database_error' => 'A sort nem sikerült menteni.',
    'database_failure' => 'Az import adatbázishiba miatt leállt.',
    'export_database_failure' => 'Az export adatbázishiba miatt leállt.',
    'status' => [
        'error' => 'Hiba',
        'skipped' => 'Kihagyva',
        'review' => 'Felülvizsgálat',
    ],
    'failed_rows' => [
        'status_header' => 'Import státusz',
        'message_header' => 'Import üzenet',
    ],
];
