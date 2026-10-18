<?php

namespace App\Services\Stock\Exceptions;

/**
 * Nothing usable is left: what remains of the item has expired
 * ("The remaining 20 Piece of Olive Oil 1L are expired").
 */
final class OnlyExpiredStock extends InsufficientStock {}
