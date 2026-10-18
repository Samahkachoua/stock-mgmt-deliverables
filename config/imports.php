<?php

/*
| Staged Excel import (App\Imports). Uploaded files are kept on `disk`: the
| local disk in development, Supabase Storage (the `s3` disk) in production.
*/

return [
    'disk' => env('IMPORT_DISK', env('FILESYSTEM_DISK', 'local')),

    // Folder on the disk for uploaded files.
    'folder' => 'imports',

    // Data rows per file (the header row not counted).
    'max_rows' => 5000,

    // Upload size limit, in kilobytes.
    'max_kilobytes' => 10240,

    // Rows read between progress updates.
    'chunk' => 250,
];
