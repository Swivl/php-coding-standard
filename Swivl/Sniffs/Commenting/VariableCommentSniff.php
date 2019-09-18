<?php

namespace Swivl\Sniffs\Commenting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\AbstractVariableSniff;

/**
 * VariableCommentSniff
 *
 * Parses and verifies the variable doc comment.
 *
 * @author Vadim Borodavko <vadim@swivl.com>
 */
class VariableCommentSniff extends AbstractVariableSniff
{
    /**
     * Called to process class member vars.
     *
     * @param File    $phpcsFile The PHP_CodeSniffer file where this token was found.
     * @param integer $stackPtr  The position where the token was found.
     */
    protected function processMemberVar(File $phpcsFile, $stackPtr): void
    {
        $this->validateVariableComment($phpcsFile, $stackPtr, true);
    }

    /**
     * Called to process normal member vars.
     *
     * @param File    $phpcsFile The PHP_CodeSniffer file where this token was found.
     * @param integer $stackPtr  The position where the token was found.
     */
    protected function processVariable(File $phpcsFile, $stackPtr): void
    {
        $this->validateVariableComment($phpcsFile, $stackPtr, false);
    }

    /**
     * Called to process variables found in double quoted strings or heredocs.
     *
     * Note that there may be more than one variable in the string, which will
     * result only in one call for the string or one call per line for heredocs.
     *
     * @param File    $phpcsFile The PHP_CodeSniffer file where this token was found.
     * @param integer $stackPtr  The position where the double quoted string was found.
     */
    protected function processVariableInString(File $phpcsFile, $stackPtr): void
    {
    }

    /**
     * Validates variable comment.
     *
     * @param File    $phpcsFile
     * @param integer $stackPtr
     * @param boolean $multiline
     */
    protected function validateVariableComment(File $phpcsFile, int $stackPtr, bool $multiline): void
    {
        $commentEnd = $phpcsFile->findPrevious(T_DOC_COMMENT_CLOSE_TAG, $stackPtr - 1);
        if ($commentEnd === false) {
            return;
        }

        $commentFor = $phpcsFile->findNext([T_VARIABLE, T_CLASS, T_INTERFACE, T_FUNCTION], $commentEnd + 1);
        if ($commentFor !== $stackPtr) {
            return;
        }

        $tokens = $phpcsFile->getTokens();
        $commentStart = $tokens[$commentEnd]['comment_opener'];
        $commentLines = $tokens[$commentEnd]['line'] - $tokens[$commentStart]['line'] + 1;

        if ($multiline && $commentLines < 3) {
            $error = 'Class member doc-comment must be multi-line';
            $phpcsFile->addError($error, $commentStart, 'Multiline');
        } elseif (!$multiline && $commentLines > 1) {
            $error = 'Variable doc-comment must be single-line';
            $phpcsFile->addError($error, $commentStart, 'Singleline');
        }

        $blockCommentStart = $commentStart;

        while ($blockCommentStart > 0 && (
            ($tokens[$blockCommentStart - 1]['code'] === T_COMMENT) ||
            ($tokens[$blockCommentStart - 1]['code'] === T_WHITESPACE
                && $tokens[$blockCommentStart - 1]['line'] === $tokens[$blockCommentStart]['line'])
            )) {
            $blockCommentStart--;
        }

        $prevToken = $phpcsFile->findPrevious(T_WHITESPACE, $blockCommentStart - 1, null, true);

        if ($prevToken !== false && in_array($tokens[$prevToken]['code'], [T_SEMICOLON, T_CLOSE_CURLY_BRACKET], true)) {
            $padding = $tokens[$blockCommentStart]['line'] - $tokens[$prevToken]['line'] - 1;

            if ($padding !== 1) {
                $error = 'There must be one blank line before the comment; found %d';
                $data = [$padding];

                if ($phpcsFile->addFixableError($error, $blockCommentStart, 'BlankLineBefore', $data)) {
                    $phpcsFile->fixer->beginChangeset();

                    if ($padding > 1) {
                        for ($i = $prevToken + 1; $i <= $blockCommentStart - 1; $i++) {
                            if ($tokens[$i]['line'] < $tokens[$blockCommentStart]['line'] - 1) {
                                $phpcsFile->fixer->replaceToken($i, '');
                            }
                        }
                    } elseif ($padding === 0) {
                        $commentLineStart = $blockCommentStart;

                        while ($commentLineStart > 0 && $tokens[$commentLineStart - 1]['line'] === $tokens[$blockCommentStart]['line']) {
                            $commentLineStart--;
                        }

                        $phpcsFile->fixer->addNewlineBefore($commentLineStart);
                    }

                    $phpcsFile->fixer->endChangeset();
                }
            }
        }

        $empty = [T_DOC_COMMENT_WHITESPACE, T_DOC_COMMENT_STAR];
        $lastTag = null;

        foreach ($tokens[$commentStart]['comment_tags'] as $tagStart) {
            $tag = $tokens[$tagStart]['content'];
            $tag = preg_replace("/^@([a-zA-Z0-9_\x7f-\xff]+).*$/", "@\\1", $tag);

            if ($lastTag !== null && $tag !== $lastTag) {
                if ($prevNotEmptyToken = $phpcsFile->findPrevious($empty, $tagStart - 1, $commentStart, true)) {
                    if ($tokens[$tagStart]['line'] - $tokens[$prevNotEmptyToken]['line'] !== 2) {
                        $error = 'There must be one blank line between different tags in the comment';

                        if ($phpcsFile->addFixableError($error, $tagStart, 'BlankLineBetweenTags')) {
                            $phpcsFile->fixer->beginChangeset();

                            for ($i = ($prevNotEmptyToken + 1); $i < $tagStart; $i++) {
                                if ($tokens[$i]['line'] === $tokens[$tagStart]['line']) {
                                    break;
                                }

                                $phpcsFile->fixer->replaceToken($i, '');
                            }

                            $star = $phpcsFile->findPrevious(T_DOC_COMMENT_STAR, $tagStart - 1, $commentStart);
                            $indent = str_repeat(' ', $tokens[$star]['column']);
                            $phpcsFile->fixer->addContent($prevNotEmptyToken, $phpcsFile->eolChar . $indent . '*' . $phpcsFile->eolChar);
                            $phpcsFile->fixer->endChangeset();
                        }
                    }
                }
            }

            $lastTag = $tag;

            if ($tag === '@var') {
                $definitionStart = $phpcsFile->findNext(T_DOC_COMMENT_WHITESPACE, $tagStart + 1, $commentEnd, true);

                if ($definitionStart === false || $tokens[$definitionStart]['code'] !== T_DOC_COMMENT_STRING) {
                    $error = 'Variable tag must be followed with a type and name';
                    $phpcsFile->addError($error, $tagStart, 'TypeAndName');
                } else {
                    $definition = preg_split('/\s+/', $tokens[$definitionStart]['content']);

                    if (count($definition) > 0 && strpos($definition[0], '$') === 0) {
                        $error = 'Variable type must go before name';

                        if ($phpcsFile->addFixableError($error, $definitionStart, 'TypeFirst')) {
                            $varType = $definition[1];
                            $varName = $definition[0];
                            $definition[0] = $varType;
                            $definition[1] = $varName;
                            $phpcsFile->fixer->replaceToken($definitionStart, implode(' ', $definition));
                        }
                    } elseif (count($definition) > 1 && strpos($definition[1], '$') === 0) {
                        $varName = $tokens[$stackPtr]['content'];

                        if ($varName !== $definition[1]) {
                            $error = 'Variable name mismatch; expected "%s" but found "%s"';
                            $data = [$varName, $definition[1]];

                            if ($phpcsFile->addFixableError($error, $definitionStart, 'NameMismatch', $data)) {
                                $definition[1] = $varName;
                                $phpcsFile->fixer->replaceToken($definitionStart, implode(' ', $definition));
                            }
                        }
                    }
                }
            }
        }
    }
}
