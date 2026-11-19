<?php

return [
    // Public ledger the admin updates by hand. Not a credential.
    'ledger_url' => 'https://docs.google.com/spreadsheets/d/1OKJvvYKqRcXoxUjqEHFTlly9nwfiMDWIsX4_lGX5kkU/edit',

    // Shown until an admin marks the ledger updated. Stored in the database after that.
    'ledger_initial_date' => '2026-11-19',

    'accounts' => ['8455', '9529', '9511'],
    'account_labels' => ['8455' => '8455 (main)', '9529' => '9529', '9511' => '9511'],

    // Used to read account numbers inside PDF statements when the server has it. Nothing is installed by the app;
    // without it, PDFs are matched by file name (and the admin can pick the account).
    'pdftotext' => env('BOOKKEEPING_PDFTOTEXT', '/usr/bin/pdftotext'),

    'distru' => [
        'api_key' => env('DISTRU_API_KEY'),
        'base_url' => rtrim((string) env('DISTRU_API_BASE', 'https://app.distru.com/public/v1'), '/'),
    ],

    // Used until an admin saves a signature on the bookkeeping page.
    'signature_html' => env('BOOKKEEPING_SIGNATURE_HTML', ''),

    'mail' => [
        // "smtp" or "gmail". Empty means not configured: the send button stays off.
        'transport' => env('BOOKKEEPING_MAIL_TRANSPORT', ''),
        'from_address' => env('BOOKKEEPING_MAIL_FROM_ADDRESS', 'billing@powerbrandsny.com'),
        'from_name' => env('BOOKKEEPING_MAIL_FROM_NAME', 'Powerbrands'),
        'smtp' => [
            'host' => env('BOOKKEEPING_SMTP_HOST'),
            'port' => (int) env('BOOKKEEPING_SMTP_PORT', 587),
            'username' => env('BOOKKEEPING_SMTP_USERNAME'),
            'password' => env('BOOKKEEPING_SMTP_PASSWORD'),
            // tls (port 587), ssl (port 465), or none.
            'encryption' => env('BOOKKEEPING_SMTP_ENCRYPTION', 'tls'),
        ],
        'gmail' => [
            'client_id' => env('BOOKKEEPING_GMAIL_CLIENT_ID'),
            'client_secret' => env('BOOKKEEPING_GMAIL_CLIENT_SECRET'),
            'refresh_token' => env('BOOKKEEPING_GMAIL_REFRESH_TOKEN'),
        ],
        // The real sender never opens a connection during tests, even if this is true.
        'allow_in_tests' => false,
    ],

    'template' => [
        'subject' => 'Outstanding Invoice(s) from Powerbrands',
        'body' => "Please review the attached invoice for the latest update. If payment is already on its way, please disregard this note. Otherwise, let me know if you have any questions or need anything resent.\n\nThank you,\n[signature]",
    ],
];
