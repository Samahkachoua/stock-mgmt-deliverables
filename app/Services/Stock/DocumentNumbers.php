<?php

namespace App\Services\Stock;

use App\Support\Drafts\DraftUi;
use App\Support\Transactions\TypeUi;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reference numbers like ISS-2026-000123: the type's prefix, the year, and the
 * next value of that type's own sequence (one per type; see the
 * 2026_10_08 migration). Taken inside the posting transaction. The counter
 * does not restart each year, and a save that fails leaves a gap.
 */
final class DocumentNumbers
{
    public function next(TypeUi $type, int $year): string
    {
        $number = DB::selectOne('SELECT nextval(?::regclass) AS n', [self::sequence($type->code)])->n;

        return sprintf('%s-%04d-%06d', $type->prefix(), $year, $number);
    }

    /**
     * A draft's reference, e.g. DRR-2026-000004 (sequence doc_no_draft_<type>_seq).
     */
    public function nextDraft(DraftUi $draft, int $year): string
    {
        $number = DB::selectOne('SELECT nextval(?::regclass) AS n', [self::sequence('DRAFT_'.$draft->code)])->n;

        return sprintf('%s-%04d-%06d', $draft->prefix(), $year, $number);
    }

    public static function sequence(string $typeCode): string
    {
        if (! preg_match('/^[A-Z][A-Z_]*$/', $typeCode)) {
            throw new InvalidArgumentException("Invalid transaction type code [$typeCode].");
        }

        return 'doc_no_'.strtolower($typeCode).'_seq';
    }
}
