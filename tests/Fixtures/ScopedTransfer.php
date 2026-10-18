<?php

namespace Tests\Fixtures;

/**
 * Like a transfer: visible from the source and the destination warehouse.
 */
class ScopedTransfer extends ScopedRecord
{
    public function warehouseColumns(): array
    {
        return ['warehouse_id', 'to_warehouse_id'];
    }
}
