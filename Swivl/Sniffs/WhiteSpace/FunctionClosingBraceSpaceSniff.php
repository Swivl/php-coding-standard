<?php

namespace Swivl\Sniffs\WhiteSpace;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * FunctionClosingBraceSpaceSniff
 *
 * Checks that there is no empty line before the closing brace of a function.
 *
 * @author    Greg Sherwood <gsherwood@squiz.net>
 * @author    Marc McIntyre <mmcintyre@squiz.net>
 * @copyright 2006-2012 Squiz Pty Ltd (ABN 77 084 670 600)
 */
class FunctionClosingBraceSpaceSniff implements Sniff
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
        return [T_FUNCTION];
    }

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File    $phpcsFile The file being scanned.
     * @param integer $stackPtr  The position of the current token in the stack passed in $tokens.
     */
    public function process(File $phpcsFile, $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();

        if (isset($tokens[$stackPtr]['scope_closer']) === false) {
            // Probably an interface method.
            return;
        }

        $closeBrace = $tokens[$stackPtr]['scope_closer'];
        $prevContent = $phpcsFile->findPrevious(T_WHITESPACE, ($closeBrace - 1), null, true);

        // Special case for empty JS functions
        if ($phpcsFile->tokenizerType === 'JS' && $prevContent === $tokens[$stackPtr]['scope_opener']) {
            // In this case, the opening and closing brace must be
            // right next to each other.
            if ($tokens[$stackPtr]['scope_closer'] !== ($tokens[$stackPtr]['scope_opener'] + 1)) {
                $error = 'The opening and closing braces of empty functions must be directly next to each other; e.g., function () {}';
                $phpcsFile->addError($error, $closeBrace, 'SpacingBetween');
            }

            return;
        }

        $braceLine = $tokens[$closeBrace]['line'];
        $prevLine = $tokens[$prevContent]['line'];

        $found = ($braceLine - $prevLine - 1);
        if ($phpcsFile->hasCondition($stackPtr, T_FUNCTION) || isset($tokens[$stackPtr]['nested_parenthesis'])) {
            // Nested function.
            if ($found < 0) {
                $error = 'Closing brace of nested function must be on a new line';

                if ($phpcsFile->addFixableError($error, $closeBrace, 'ContentBeforeClose')) {
                    $phpcsFile->fixer->addNewlineBefore($closeBrace);
                }
            } elseif ($found > 0) {
                $error = 'Expected 0 blank lines before closing brace of nested function; %s found';
                $data = [$found];

                if ($phpcsFile->addFixableError($error, $closeBrace, 'SpacingBeforeNestedClose', $data)) {
                    $phpcsFile->fixer->beginChangeset();

                    for ($i = $prevContent + 1; $i < $braceLine; $i++) {
                        if ($tokens[$i]['line'] < $braceLine) {
                            $phpcsFile->fixer->replaceToken($i, '');
                        }
                    }

                    $phpcsFile->fixer->endChangeset();
                }
            }
        } elseif ($found > 0) {
            $error = 'Expected 0 blank line before closing function brace; %s found';
            $data = [$found];
            if ($phpcsFile->addFixableError($error, $closeBrace, 'SpacingBeforeClose', $data)) {
                $phpcsFile->fixer->beginChangeset();

                for ($i = $prevContent + 1; $i < $braceLine; $i++) {
                    if ($tokens[$i]['line'] < $braceLine) {
                        $phpcsFile->fixer->replaceToken($i, '');
                    }
                }

                $phpcsFile->fixer->endChangeset();
            }
        }
    }
}
