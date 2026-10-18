<?php

namespace App\Services\Stock\Exceptions;

/**
 * The user may not do this here (not their warehouse, not the sender, not the destination).
 */
final class NotAllowed extends StockException {}
