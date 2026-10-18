<?php

namespace App\Services\Stock\Exceptions;

/**
 * Someone else got there first: the document was already received, approved,
 * rejected or cancelled ("This transfer was already received by Nour at 10:42").
 */
final class AlreadyProcessed extends StockException {}
