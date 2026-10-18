<?php

namespace App\Services\Stock\Exceptions;

/**
 * The document breaks a rule (unknown item, invalid unit, missing expiry date...).
 */
final class PostingRejected extends StockException {}
