<?php

/**
 * Invoices (docs/plans/invoices.md). The issuer data are placeholders until the owner and the accountant confirm the
 * real ones: set them in .env, never invent fiscal data here. Tax is zero until the accountant says what applies.
 */
return [
    'series' => ['invoice' => 'QV', 'credit_note' => 'NC'],

    'tax' => [
        // Percent. The charged amount is treated as tax-inclusive (the buyer pays what the checkout showed).
        'rate' => (float) env('INVOICING_TAX_RATE', 0),
        'label' => env('INVOICING_TAX_LABEL', 'Tax'),
    ],

    'issuer' => [
        'name' => env('INVOICING_ISSUER_NAME', '[Razón social pendiente]'),
        'address' => env('INVOICING_ISSUER_ADDRESS', '[Dirección pendiente]'),
        'tax_id' => env('INVOICING_ISSUER_TAX_ID', '[Identificación fiscal pendiente]'),
        'email' => env('INVOICING_ISSUER_EMAIL', env('MAIL_FROM_ADDRESS')),
        'website' => env('INVOICING_ISSUER_WEBSITE', 'quinvite.com'),
    ],

    // Private disk: PDFs are only served through the API after a policy check.
    'disk' => env('INVOICING_DISK', 'local'),
    'path' => 'invoices',
];
