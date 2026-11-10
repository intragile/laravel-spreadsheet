<?php

declare(strict_types=1);

return [
    'export' => [
        // Rows fetched per keyset page.
        'chunk_size' => 1000,

        'csv' => [
            'delimiter' => ',',
            'bom' => false,
            'escape_formulas' => true,
        ],
    ],

    'import' => [],

    'templates' => [
        // Mapping template store; null disables persistence.
        'store' => null,
    ],
];
