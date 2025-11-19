<?php

namespace Swivl\Helpers;

use PHP_CodeSniffer\Util\Common as SnifferCommon;

final class Common extends SnifferCommon
{
    /**
     * @var string[]
     */
    public const SHORT_SCALAR_TYPES = ['int', 'bool'];

    /**
     * {@inheritDoc}
     */
    public static function suggestType(string $varType)
    {
        if (in_array($varType, self::SHORT_SCALAR_TYPES, true)) {
            return $varType;
        }

        return parent::suggestType($varType);
    }
}
