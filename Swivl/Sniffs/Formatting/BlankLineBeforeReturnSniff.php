<?php

namespace Swivl\Sniffs\Formatting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * BlankLineBeforeReturnSniff
 *
 * Throws errors if there's no blank line before return statements. Symfony
 * coding standard specifies: "Add a blank line before return statements,
 * unless the return is alone inside a statement-group (like an if statement);"
 *
 * @author Dave Hauenstein <davehauenstein@gmail.com>
 */
class BlankLineBeforeReturnSniff implements Sniff
{
    /**
     * A list of tokenizers this sniff supports.
     *
     * @var array
     */
    public $supportedTokenizers = ['PHP', 'JS'];

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array
     */
    public function register(): array
    {
        return [T_RETURN];
    }

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File    $phpcsFile All the tokens found in the document.
     * @param integer $stackPtr  The position of the current token in the stack passed in $tokens.
     */
    public function process(File $phpcsFile, $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $current = $stackPtr;
        $previousLine = $tokens[$stackPtr]['line'] - 1;
        $prevLineTokens = [];

        while ($current >= 0 && $tokens[$current]['line'] >= $previousLine) {
            if ($tokens[$current]['line'] === $previousLine
                && $tokens[$current]['type'] !== 'T_WHITESPACE'
                && $tokens[$current]['type'] !== 'T_COMMENT'
            ) {
                $prevLineTokens[] = $tokens[$current]['type'];
            }
            $current--;
        }

        if (isset($prevLineTokens[0])
            && ($prevLineTokens[0] === 'T_OPEN_CURLY_BRACKET' || $prevLineTokens[0] === 'T_COLON')) {
            return;
        }

        if (count($prevLineTokens) > 0) {
            $prevDefaultTokenPtr = $phpcsFile->findPrevious(T_DEFAULT, $stackPtr);
            $prevCaseTokenPtr = $phpcsFile->findPrevious(T_CASE, $stackPtr);

            if (($prevDefaultTokenPtr !== false && $tokens[$prevDefaultTokenPtr]['scope_closer'] === $stackPtr)
                || ($prevCaseTokenPtr !== false && $tokens[$prevCaseTokenPtr]['scope_closer'] === $stackPtr)) {
                // return as break of switch case or default
                return;
            }

            if ($phpcsFile->addFixableError('Missing blank line before return statement', $stackPtr, 'Missing')) {
                $returnStart = $stackPtr;

                while ($returnStart > 0 && $tokens[$returnStart - 1]['line'] === $tokens[$stackPtr]['line']) {
                    $returnStart--;
                }

                $phpcsFile->fixer->addNewlineBefore($returnStart);
            }
        }
    }
}
