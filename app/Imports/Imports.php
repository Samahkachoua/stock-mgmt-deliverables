<?php

namespace App\Imports;

use App\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The kinds of import, by URL segment and by import_job.job_type.
 */
final class Imports
{
    /** @var list<class-string<ImportType>> */
    public const ALL = [ProductImport::class, OpeningBalanceImport::class];

    /**
     * @return array<string, ImportType> keyed by slug
     */
    public static function all(): array
    {
        $types = [];

        foreach (self::ALL as $class) {
            $type = new $class;
            $types[$type->slug()] = $type;
        }

        return $types;
    }

    public static function get(string $slug): ImportType
    {
        return self::all()[$slug] ?? throw new NotFoundHttpException("No import [$slug].");
    }

    public static function forCode(string $code): ImportType
    {
        foreach (self::all() as $type) {
            if ($type->code() === $code) {
                return $type;
            }
        }

        throw new NotFoundHttpException("No import [$code].");
    }

    /**
     * @return array<string, ImportType>
     */
    public static function allowedFor(User $user): array
    {
        return array_filter(self::all(), fn (ImportType $type) => $type->allows($user));
    }
}
