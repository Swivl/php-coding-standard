<?php

namespace Swivl\Sniffs\Commenting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Standards\Squiz\Sniffs\Commenting\ClassCommentSniff as SquizClassCommentSniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * ClassCommentSniff
 *
 * @author Vadim Borodavko <vadim@swivl.com>
 */
class ClassCommentSniff extends SquizClassCommentSniff
{
    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File    $phpcsFile The file being scanned.
     * @param integer $stackPtr  The position of the current token in the stack passed in $tokens.
     */
    public function process(File $phpcsFile, $stackPtr): void
    {
        parent::process($phpcsFile, $stackPtr);

        $tokens = $phpcsFile->getTokens();

        $find = Tokens::$methodPrefixes;
        $find[] = T_WHITESPACE;
        $commentEnd = $phpcsFile->findPrevious($find, $stackPtr - 1, null, true);
        if ($commentEnd === false || $tokens[$commentEnd]['code'] !== T_DOC_COMMENT_CLOSE_TAG) {
            return;
        }

        $commentStart = $tokens[$commentEnd]['comment_opener'];
        $find = [T_DOC_COMMENT_WHITESPACE, T_DOC_COMMENT_STAR];
        $shortCommentStart = $phpcsFile->findNext($find, $commentStart + 1, $commentEnd, true);
        if ($shortCommentStart === false || $tokens[$shortCommentStart]['code'] !== T_DOC_COMMENT_STRING) {
            return;
        }

        $shortComment = trim($tokens[$shortCommentStart]['content']);

        $classNamePtr = $phpcsFile->findNext(T_STRING, $stackPtr);
        $className = $tokens[$classNamePtr]['content'];
        $words = explode(' ', $shortComment);

        if (((strpos($shortComment, ' ') === false) && ($shortComment !== $className))) {
            $error = 'Class short description does not match class name; expected %s but found %s';
            $data = [$className, $shortComment];

            if ($phpcsFile->addFixableError($error, $shortCommentStart, 'ClassDescriptionMismatch', $data)) {
                $phpcsFile->fixer->replaceToken($shortCommentStart, $className);
            }
        } elseif ((strtolower($words[0]) === 'class') && ($words[1] !== $className)) {
            $error = 'Class short description does not match class name; expected %s but found %s';
            $data = [$className, $words[1]];

            if ($phpcsFile->addFixableError($error, $shortCommentStart, 'ClassDescriptionMismatch', $data)) {
                $phpcsFile->fixer->replaceToken($shortCommentStart, $words[0] . ' ' . $className);
            }
        }

        foreach ($tokens[$commentStart]['comment_tags'] as $tag) {
            if ($tokens[$tag]['content'] === '@package') {
                if ($packageStart = $phpcsFile->findNext(T_DOC_COMMENT_STRING, $tag, $commentEnd)) {
                    $packageName = $tokens[$packageStart]['content'];

                    if ($namespacePtr = $phpcsFile->findPrevious(T_NAMESPACE, $stackPtr - 1, 0)) {
                        $namespaceStartPtr = $phpcsFile->findNext(T_STRING, $namespacePtr);
                        $namespaceEndPtr = $phpcsFile->findNext(T_SEMICOLON, ($namespaceStartPtr + 1));
                        $namespaceName = $phpcsFile->getTokensAsString($namespaceStartPtr, ($namespaceEndPtr - $namespaceStartPtr));

                        if ($packageName !== $namespaceName) {
                            $error = 'Class package does not match class namespace; expected %s but found %s';
                            $data = [$namespaceName, $packageName];

                            if ($phpcsFile->addFixableError($error, $packageStart, 'ClassPackageMismatch', $data)) {
                                $phpcsFile->fixer->replaceToken($packageStart, $namespaceName);
                            }
                        }
                    }
                }
            }
        }
    }
}
