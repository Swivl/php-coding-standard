<?php

namespace Swivl\Sniffs\Functions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Standards\PEAR\Sniffs\Functions\FunctionDeclarationSniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * MultiLineFunctionDeclarationSniff
 *
 * Ensure multi-line function declarations are defined correctly.
 */
class MultiLineFunctionDeclarationSniff extends FunctionDeclarationSniff
{
    /**
     * Processes multi-line declarations.
     *
     * @param File    $phpcsFile The file being scanned.
     * @param integer $stackPtr  The position of the current token in the stack passed in $tokens.
     * @param array   $tokens    The stack of tokens that make up the file.
     */
    public function processMultiLineDeclaration($phpcsFile, $stackPtr, $tokens): void
    {
        $this->processArgumentList($phpcsFile, $stackPtr, $this->indent);

        // Taken from parent::processArgumentList():379-391
        $functionIndent = 0;
        for ($i = ($stackPtr - 1); $i >= 0; $i--) {
            if ($tokens[$i]['line'] !== $tokens[$stackPtr]['line']) {
                break;
            }
        }

        // Move $i back to the line the function is or to 0.
        $i++;

        if ($tokens[$i]['code'] === T_WHITESPACE) {
            $functionIndent = $tokens[$i]['length'];
        }

        // Taken from parent::processMultiLineDeclaration():287-355 and modified
        $closeBracket = $tokens[$stackPtr]['parenthesis_closer'];
        if ($tokens[$stackPtr]['code'] === T_CLOSURE) {
            $use = $phpcsFile->findNext(T_USE, ($closeBracket + 1), $tokens[$stackPtr]['scope_opener']);
            if ($use !== false) {
                $open = $phpcsFile->findNext(T_OPEN_PARENTHESIS, ($use + 1));
                $closeBracket = $tokens[$open]['parenthesis_closer'];
            }
        }

        if (isset($tokens[$stackPtr]['scope_opener']) === false) {
            return;
        }

        // The opening brace needs to be on the next line from the closing parenthesis.
        $opener = $tokens[$stackPtr]['scope_opener'];
        if ($tokens[$opener]['line'] !== $tokens[$closeBracket]['line'] + 1) {
            $error = 'There must be a newline between the closing parenthesis and the opening brace of a multi-line function declaration';
            $fix = $phpcsFile->addFixableError($error, $opener, 'NewlineBeforeOpenBrace');

            if ($fix === true) {
                $prev = $phpcsFile->findPrevious(Tokens::$emptyTokens, ($opener - 1), $closeBracket, true);
                $phpcsFile->fixer->addContent($prev, $phpcsFile->eolChar);
            }
        } else {
            $prev = $tokens[($opener - 1)];
            if ($prev['code'] !== T_WHITESPACE) {
                $length = 0;
            } else {
                $length = strlen($prev['content']);
            }

            if ($length !== $functionIndent) {
                $error = 'There must be %s spaces before the opening brace of a multi-line function declaration; found %s spaces';
                $fix = $phpcsFile->addFixableError($error, $opener, 'SpaceBeforeOpenBrace', [$functionIndent, $length]);

                if ($fix === true) {
                    $content = str_repeat(' ', $functionIndent);
                    if ($length === 0) {
                        $phpcsFile->fixer->addContentBefore($opener, $content);
                    } else {
                        $phpcsFile->fixer->replaceToken(($opener - 1), $content);
                    }
                }

                return;
            }
        }
    }
}
