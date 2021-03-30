<?php

namespace Swivl\Helpers;

use PHP_CodeSniffer\Files\File;

class DocCommentHelper
{
    public static function getDescription(File $phpcsFile, int $commentStartPtr, int $commentEndPtr): ?string
    {
        $tokens = $phpcsFile->getTokens();

        if ($tokens[$commentStartPtr]['comment_tags']) {
            $descriptionEndPtr = $tokens[$commentStartPtr]['comment_tags'][0] - 1;
        } else {
            $descriptionEndPtr = $commentEndPtr;
        }

        $comment = $phpcsFile->getTokensAsString($commentStartPtr, $descriptionEndPtr - $commentStartPtr + 1);

        return trim(strtok($comment, '@'), "/* \n");
    }

    public static function getDocCommentStart(File $phpcsFile, int $commentEndPtr): ?int
    {
        $tokens = $phpcsFile->getTokens();

        if ($tokens[$commentEndPtr]['code'] !== T_DOC_COMMENT_CLOSE_TAG) {
            return null;
        }

        return $tokens[$commentEndPtr]['comment_opener'];
    }

    public static function getDocCommentEnd(File $phpcsFile, int $commentStartPtr): ?int
    {
        $tokens = $phpcsFile->getTokens();

        if ($tokens[$commentStartPtr]['code'] !== T_DOC_COMMENT_OPEN_TAG) {
            return null;
        }

        return $tokens[$commentStartPtr]['comment_closer'];
    }

    public static function removeDocComment(File $phpcsFile, int $commentStartPtr): void
    {
        $commentEndPtr = self::getDocCommentEnd($phpcsFile, $commentStartPtr);

        $endPtr = $phpcsFile->findNext(T_WHITESPACE, $commentEndPtr + 1, null, true);

        $phpcsFile->fixer->beginChangeset();

        for ($i = $commentStartPtr; $i < $endPtr; $i++) {
            $phpcsFile->fixer->replaceToken($i, '');
        }

        $phpcsFile->fixer->endChangeset();
    }
}
