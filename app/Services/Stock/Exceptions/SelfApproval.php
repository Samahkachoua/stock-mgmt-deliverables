<?php

namespace App\Services\Stock\Exceptions;

/**
 * The approver is the person who created the document (CLAUDE.md rule 6).
 */
final class SelfApproval extends StockException {}
