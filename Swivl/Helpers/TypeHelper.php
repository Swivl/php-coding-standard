<?php

namespace Swivl\Helpers;

use PHP_CodeSniffer\Util\Common;

class TypeHelper
{
    private const TRAVERSABLE_TYPE_MAP = [
        'array' => true,
        'iterable' => true,
        'iteratoraggregate' => true,
        'traversable' => true,
    ];

    private const SHORT_SCALAR_TYPES = ['int', 'bool'];

    public static function isTypeTraversable(string $mixedType): bool
    {
        foreach (explode('|', self::normalizeType($mixedType)) as $type) {
            $type = strtolower(ltrim($type, '\\'));
            $typeLen = strlen($type);

            if (
                isset(self::TRAVERSABLE_TYPE_MAP[$type])
                || ($typeLen >= 10 && substr($type, -10) === 'collection')
                || ($typeLen >= 8 && substr($type, -8) === 'iterator')
            ) {
                return true;
            }
        }

        return false;
    }

    public static function allowShortScalarTypes(): void
    {
        if ($missedScalarTypes = array_diff(self::SHORT_SCALAR_TYPES, Common::$allowedTypes)) {
            Common::$allowedTypes = array_merge(Common::$allowedTypes, $missedScalarTypes);
        }
    }

    public static function normalizeType(string $mixedType): string
    {
        if ($mixedType !== '' && $mixedType[0] === '?') {
            $mixedType = substr($mixedType, 1) . '|null';
        }

        return $mixedType;
    }
}
