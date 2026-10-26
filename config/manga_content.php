<?php

return [
    // Required only for PDF chapters. Use absolute executable paths on Windows.
    // Gate12B opt-in: requires QUEUE_CONNECTION=database and a running named worker.
    'async_imports' => (bool) env('MANGA_ASYNC_CHAPTER_IMPORTS', false),
    'pdfinfo_binary' => env('MANGA_PDFINFO_BIN', 'pdfinfo'),
    'pdftoppm_binary' => env('MANGA_PDFTOPPM_BIN', 'pdftoppm'),
    // Synchronous PDF rendering: cap work to avoid exhausting the PHP request.
    'max_pdf_pages' => (int) env('MANGA_MAX_PDF_PAGES', 40),
    'pdf_timeout' => (int) env('MANGA_PDF_TIMEOUT', 180),
];
