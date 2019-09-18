<?php

namespace Swivl\Sniffs\NamingConventions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * InterfaceSuffixSniff
 *
 * Throws errors if interface names are not suffixed with "Interface".
 *
 * Symfony coding standard specifies: "Suffix interfaces with Interface;"
 *
 * @author Dave Hauenstein <davehauenstein@gmail.com>
 */
class InterfaceSuffixSniff implements Sniff
{
    /**
     * A list of tokenizers this sniff supports.
     *
     * @var array
     */
    public $supportedTokenizers = ['PHP'];

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array
     */
    public function register(): array
    {
        return [T_INTERFACE];
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
        $line = $tokens[$stackPtr]['line'];

        while ($tokens[$stackPtr]['line'] === $line) {
            if ($tokens[$stackPtr]['code'] === T_STRING) {
                if (substr($tokens[$stackPtr]['content'], -9) !== 'Interface') {
                    if ($phpcsFile->addFixableError('Interface name is not suffixed with "Interface"', $stackPtr, 'Missing')) {
                        $phpcsFile->fixer->replaceToken($stackPtr, $tokens[$stackPtr]['content'] . 'Interface');
                    }
                }

                break;
            }

            $stackPtr++;
        }
    }
}
