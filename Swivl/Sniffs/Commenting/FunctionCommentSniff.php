<?php

namespace Swivl\Sniffs\Commenting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Commenting\DocCommentSniff;
use PHP_CodeSniffer\Standards\Squiz\Sniffs\Commenting\FunctionCommentSniff as SquizFunctionCommentSniff;

/**
 * FunctionCommentSniff
 *
 * @author Vadim Borodavko <vadim@swivl.com>
 */
class FunctionCommentSniff extends SquizFunctionCommentSniff
{
    /**
     * @var DocCommentSniff
     */
    private $docCommentSniff;

    /**
     * Process the return comment of this function comment.
     *
     * @param File    $phpcsFile    The file being scanned.
     * @param integer $stackPtr     The position of the current token in the stack passed in $tokens.
     * @param integer $commentStart The position in the stack where the comment started.
     */
    protected function processReturn(File $phpcsFile, $stackPtr, $commentStart): void
    {
        $this->processUnknownTags($phpcsFile, $stackPtr, $commentStart);

        if ($this->isInheritDoc($phpcsFile, $stackPtr, $commentStart)) {
            return;
        }

        $this->processDocComment($phpcsFile, $stackPtr, $commentStart);

        $tokens = $phpcsFile->getTokens();

        // Only check for a return comment if a non-void return statement exists
        if (isset($tokens[$stackPtr]['scope_opener'])) {
            $start = $tokens[$stackPtr]['scope_opener'];

            // iterate over all return statements of this function,
            // run the check on the first which is not only 'return;'
            while ($returnToken = $phpcsFile->findNext(T_RETURN, $start, $tokens[$stackPtr]['scope_closer'])) {
                if ($this->isMatchingReturn($tokens, $returnToken)) {
                    parent::processReturn($phpcsFile, $stackPtr, $commentStart);
                    break;
                }
                $start = $returnToken + 1;
            }
        }
    }

    /**
     * Is the comment an inheritdoc?
     *
     * @param File    $phpcsFile    The file being scanned.
     * @param integer $stackPtr     The position of the current token
     *                              in the stack passed in $tokens.
     * @param integer $commentStart The position in the stack where the comment started.
     *
     * @return boolean True if the comment is an inheritdoc
     */
    protected function isInheritDoc(File $phpcsFile, int $stackPtr, $commentStart): bool
    {
        $commentString = $phpcsFile->getTokensAsString($commentStart, ($stackPtr - $commentStart + 1));

        return preg_match('#{@inheritdoc}#i', $commentString) === 1;
    }

    /**
     * Process the function parameter comments.
     *
     * @param File    $phpcsFile    The file being scanned.
     * @param integer $stackPtr     The position of the current token in the stack passed in $tokens.
     * @param integer $commentStart The position in the stack where the comment started.
     */
    protected function processParams(File $phpcsFile, $stackPtr, $commentStart): void
    {
        if ($this->isInheritDoc($phpcsFile, $stackPtr, $commentStart)) {
            return;
        }

        parent::processParams($phpcsFile, $stackPtr, $commentStart);
    }

    /**
     * Is the return statement matching?
     *
     * @param array   $tokens    Array of tokens
     * @param integer $returnPos Stack position of the T_RETURN token to process
     *
     * @return boolean True if the return does not return anything
     */
    protected function isMatchingReturn(array $tokens, int $returnPos): bool
    {
        do {
            $returnPos++;
        } while ($tokens[$returnPos]['code'] === T_WHITESPACE);

        return $tokens[$returnPos]['code'] !== T_SEMICOLON;
    }

    /**
     * Process a list of unknown tags.
     *
     * @param File    $phpcsFile    The file being scanned.
     * @param integer $stackPtr     The position of the current token in the stack passed in $tokens.
     * @param integer $commentStart The position in the stack where the comment started.
     */
    protected function processUnknownTags(File $phpcsFile, int $stackPtr, int $commentStart): void
    {
        $tokens = $phpcsFile->getTokens();

        $prevToken = $phpcsFile->findPrevious(T_WHITESPACE, $commentStart - 1, null, true);

        if ($prevToken !== false && $tokens[$prevToken]['code'] !== T_OPEN_CURLY_BRACKET) {
            $padding = $tokens[$commentStart]['line'] - $tokens[$prevToken]['line'] - 1;

            if ($padding !== 1) {
                $error = 'There must be one blank line before the comment';

                if ($phpcsFile->addFixableError($error, $commentStart, 'BlankLineBefore')) {
                    $phpcsFile->fixer->beginChangeset();

                    if ($padding > 1) {
                        for ($i = $prevToken + 1; $i <= $commentStart - 1; $i++) {
                            if ($tokens[$i]['line'] < $tokens[$commentStart]['line'] - 1) {
                                $phpcsFile->fixer->replaceToken($i, '');
                            }
                        }
                    } elseif ($padding === 0) {
                        $commentLineStart = $commentStart;

                        while ($commentLineStart > 0 && $tokens[$commentLineStart - 1]['line'] === $tokens[$commentStart]['line']) {
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
        $lastTagColumn = null;

        foreach ($tokens[$commentStart]['comment_tags'] as $tagStart) {
            $tag = $tokens[$tagStart]['content'];
            $tag = preg_replace("/^@([a-zA-Z0-9\\\\_\x7f-\xff]+).*$/", "@\\1", $tag);
            $tagColumn = $tokens[$tagStart]['column'];

            if ($lastTagColumn !== null && $tagColumn > $lastTagColumn) {
                // Skip nested tags
                continue;
            }

            if ($lastTag !== null && $tag !== $lastTag) {
                if ($prevNotEmptyToken = $phpcsFile->findPrevious($empty, $tagStart - 1, $commentStart, true)) {
                    $padding = $tokens[$tagStart]['line'] - $tokens[$prevNotEmptyToken]['line'] - 1;

                    if ($padding !== 1) {
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
            $lastTagColumn = $tagColumn;
        }
    }

    /**
     * Process generic Doc Comment.
     *
     * @param File    $phpcsFile    The file being scanned.
     * @param integer $stackPtr     The position of the current token in the stack passed in $tokens.
     * @param integer $commentStart The position in the stack where the comment started.
     */
    protected function processDocComment(File $phpcsFile, $stackPtr, $commentStart): void
    {
        if (!$this->docCommentSniff) {
            $this->docCommentSniff = new DocCommentSniff();
        }

        $this->docCommentSniff->process($phpcsFile, $commentStart);
    }
}
