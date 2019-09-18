<?php

namespace Swivl\Sniffs\Classes;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Standards\PSR2\Sniffs\Classes\ClassDeclarationSniff as PSR2ClassDeclarationSniff;

/**
 * ClassDeclarationSniff
 *
 * Checks the declaration of the class is correct.
 *
 * @author Vadim Borodavko <vadim@swivl.com>
 */
class ClassDeclarationSniff extends PSR2ClassDeclarationSniff
{
    /**
     * Processes the opening section of a class declaration.
     *
     * @param File    $phpcsFile The file being scanned.
     * @param integer $stackPtr  The position of the current token in the stack passed in $tokens.
     */
    public function processOpen(File $phpcsFile, $stackPtr): void
    {
        parent::processOpen($phpcsFile, $stackPtr);

        $tokens = $phpcsFile->getTokens();

        if (isset($tokens[$stackPtr]['scope_opener']) === false) {
            return;
        }

        // Check that the code body comes right after the opening brace
        $openingBrace = $tokens[$stackPtr]['scope_opener'];
        $nextContent = $phpcsFile->findNext(T_WHITESPACE, $openingBrace + 1, null, true);

        if ($nextContent !== $openingBrace && $tokens[$nextContent]['line'] !== ($tokens[$openingBrace]['line'] + 1)) {
            $error = 'The body of the %s must go on the next line after the opening brace';
            $data = [$tokens[$stackPtr]['content']];

            if ($phpcsFile->addFixableError($error, $nextContent, 'BodyAfterOpenBrace', $data)) {
                $phpcsFile->fixer->beginChangeset();

                for ($i = $openingBrace + 1; $i <= $nextContent; $i++) {
                    if ($tokens[$i]['line'] !== $tokens[$nextContent]['line']) {
                        $phpcsFile->fixer->replaceToken($i, '');
                    }
                }

                $phpcsFile->fixer->endChangeset();
            }
        }
    }
}
