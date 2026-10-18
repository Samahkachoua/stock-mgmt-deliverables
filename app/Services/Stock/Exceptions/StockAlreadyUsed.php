<?php

namespace App\Services\Stock\Exceptions;

/**
 * A receipt can't be cancelled because part of its stock has already gone out.
 */
final class StockAlreadyUsed extends StockException {}
