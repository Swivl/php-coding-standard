<?php

namespace Swivl\Sniffs\WhiteSpace;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * DiscourageFitzinatorSniff
 *
 * Throws warnings if a file contains trailing whitespace.
 *
 * @author   Justin Hileman <justin@shopopensky.com>
 */
class DiscourageFitzinatorSniff implements Sniff
{
    /**
     * A list of tokenizers this sniff supports.
     *
     * @var array
     */
    public $supportedTokenizers = ['PHP', 'JS', 'CSS'];

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array
     */
    public function register(): array
    {
        return [T_WHITESPACE];
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

        // Make sure this is trailing whitespace.
        $line = $tokens[$stackPtr]['line'];
        if (($stackPtr < count($tokens) - 1) && $tokens[($stackPtr + 1)]['line'] === $line) {
            return;
        }

        if (strpos($tokens[$stackPtr]['content'], "\n") > 0 || strpos($tokens[$stackPtr]['content'], "\r") > 0) {
            $warning = 'Please trim any trailing whitespace';

            if ($phpcsFile->addFixableWarning($warning, $stackPtr, 'Discouraged')) {
                $phpcsFile->fixer->replaceToken($stackPtr, '');
            }
        }
    }
}
