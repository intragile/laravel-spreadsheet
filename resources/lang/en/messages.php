<?php

declare(strict_types=1);

return [
    'invalid_date' => '":value" is not a valid date.',
    'ambiguous_date' => '":value" could be day/month or month/day; choose the date layout of this column.',
    'invalid_number' => '":value" is not a valid number.',
    'invalid_integer' => '":value" is not a valid whole number.',
    'invalid_boolean' => '":value" is not a recognised yes/no value.',
    'unknown_value' => '":value" is not a known value.',
    'unknown_option' => '":value" is not one of the allowed options: :options.',
    'invalid_domain' => '":value" is not a valid domain name.',
    'currency_from_symbol' => 'Currency ":value" was read as :code.',
    // Variants without the cell value, used for sensitive fields.
    'redacted' => [
        'invalid_date' => 'Not a valid date.',
        'ambiguous_date' => 'Could be day/month or month/day; choose the date layout of this column.',
        'invalid_number' => 'Not a valid number.',
        'invalid_integer' => 'Not a valid whole number.',
        'invalid_boolean' => 'Not a recognised yes/no value.',
        'unknown_value' => 'Not a known value.',
        'unknown_option' => 'Not one of the allowed options: :options.',
        'invalid_domain' => 'Not a valid domain name.',
        'currency_from_symbol' => 'The currency symbol was read as :code.',
    ],
    'duplicate_in_file' => 'Duplicate of row :row.',
    'possible_duplicate_in_file' => 'Possibly the same as row :row; check it before importing.',
    'possible_duplicate' => 'Possibly the same as an existing record (#:id); check it before importing.',
    'matches_trashed' => 'Matches a deleted record.',
    'database_error' => 'The row could not be saved.',
    'database_failure' => 'The import stopped because of a database error.',
    'export_database_failure' => 'The export stopped because of a database error.',
    'status' => [
        'error' => 'Error',
        'skipped' => 'Skipped',
        'review' => 'Needs review',
    ],
    'failed_rows' => [
        'status_header' => 'Import status',
        'message_header' => 'Import message',
    ],
];
