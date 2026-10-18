<?php

namespace App\Imports;

use RuntimeException;

/**
 * An import that can't go on, with a message for the user (wrong file type,
 * too many rows, already imported...).
 */
final class ImportRefused extends RuntimeException {}
