<?php

namespace Swivl\Helpers;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;

class FunctionHelper
{
    private const METHOD_NAMES_WITHOUT_RETURN_TYPE_MAP = [
        '__construct' => true,
        '__destruct' => true,
        '__clone' => true,
    ];

    public static function isDocCommentRequired(File $phpcsFile, int $functionPtr): bool
    {
        return self::isDocCommentReturnRequired($phpcsFile, $functionPtr)
            || self::isDocCommentParametersRequired($phpcsFile, $functionPtr);
    }

    public static function isDocCommentReturnRequired(File $phpcsFile, int $functionPtr): bool
    {
        $methodProperties = $phpcsFile->getMethodProperties($functionPtr);
        $returnType = $methodProperties['return_type'];

        if (
            !isset(self::METHOD_NAMES_WITHOUT_RETURN_TYPE_MAP[$phpcsFile->getDeclarationName($functionPtr)])
            && (!$returnType || TypeHelper::isTypeTraversable($returnType))
        ) {
            return true;
        }

        return false;
    }

    public static function isDocCommentParametersRequired(File $phpcsFile, int $functionPtr): bool
    {
        $methodParameters = $phpcsFile->getMethodParameters($functionPtr);

        foreach ($methodParameters as $pos => $param) {
            $paramType = $param['type_hint'];

            if (!$paramType || TypeHelper::isTypeTraversable($paramType)) {
                return true;
            }
        }

        return false;
    }

    public static function getDocCommentEnd(File $phpcsFile, int $functionPtr): ?int
    {
        $tokens = $phpcsFile->getTokens();
        $find = Tokens::$methodPrefixes;
        $find[] = T_WHITESPACE;
        $commentEndPtr = $phpcsFile->findPrevious($find, $functionPtr - 1, null, true);

        if ($tokens[$commentEndPtr]['code'] !== T_DOC_COMMENT_CLOSE_TAG) {
            return null;
        }

        return $commentEndPtr;
    }

    public static function getClassPtr(File $phpcsFile, int $functionPtr): ?int
    {
        return $phpcsFile->findPrevious([T_CLASS, T_INTERFACE, T_TRAIT], $functionPtr - 1) ?: null;
    }

    public static function getClassName(File $phpcsFile, int $functionPtr): ?string
    {
        $classPtr = self::getClassPtr($phpcsFile, $functionPtr);

        return $classPtr !== null ? $phpcsFile->getDeclarationName($classPtr) : null;
    }
}
