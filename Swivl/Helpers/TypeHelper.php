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

    public static function normalizeType(string $mixedType): string
    {
        if ($mixedType !== '' && $mixedType[0] === '?') {
            $mixedType = substr($mixedType, 1) . '|null';
        }

        return $mixedType;
    }
}
