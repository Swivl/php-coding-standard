<?php

namespace Swivl\Sniffs\Functions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Standards\PEAR\Sniffs\Functions\FunctionDeclarationSniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * MultiLineFunctionDeclarationSniff
 *
 * Ensure single and multi-line function declarations are defined correctly.
 *
 * @author    Greg Sherwood <gsherwood@squiz.net>
 * @copyright 2006-2015 Squiz Pty Ltd (ABN 77 084 670 600)
 * @license   https://github.com/squizlabs/PHP_CodeSniffer/blob/master/licence.txt BSD Licence
 */
class MultiLineFunctionDeclarationSniff extends FunctionDeclarationSniff
{
    /**
     * Processes mutli-line declarations.
     *
     * @param File    $phpcsFile The file being scanned.
     * @param integer $stackPtr  The position of the current token in the stack passed in $tokens.
     * @param array   $tokens    The stack of tokens that make up the file.
     */
    public function processMultiLineDeclaration($phpcsFile, $stackPtr, $tokens): void
    {
        // We need to work out how far indented the function
        // declaration itself is, so we can work out how far to
        // indent parameters.
        $functionIndent = 0;
        for ($i = ($stackPtr - 1); $i >= 0; $i--) {
            if ($tokens[$i]['line'] !== $tokens[$stackPtr]['line']) {
                $i++;
                break;
            }
        }

        if ($tokens[$i]['code'] === T_WHITESPACE) {
            $functionIndent = strlen($tokens[$i]['content']);
        }

        // The closing parenthesis must be on a new line, even
        // when checking abstract function definitions.
        $closeBracket = $tokens[$stackPtr]['parenthesis_closer'];
        $prev = $phpcsFile->findPrevious(
            T_WHITESPACE,
            ($closeBracket - 1),
            null,
            true
        );

        if ($tokens[$closeBracket]['line'] !== $tokens[$tokens[$closeBracket]['parenthesis_opener']]['line']) {
            if ($tokens[$prev]['line'] === $tokens[$closeBracket]['line']) {
                $error = 'The closing parenthesis of a multi-line function declaration must be on a new line';
                $fix = $phpcsFile->addFixableError($error, $closeBracket, 'CloseBracketLine');
                if ($fix === true) {
                    $phpcsFile->fixer->addNewlineBefore($closeBracket);
                }
            }
        }

        // If this is a closure and is using a USE statement, the closing
        // parenthesis we need to look at from now on is the closing parenthesis
        // of the USE statement.
        if ($tokens[$stackPtr]['code'] === T_CLOSURE) {
            $use = $phpcsFile->findNext(T_USE, ($closeBracket + 1), $tokens[$stackPtr]['scope_opener']);
            if ($use !== false) {
                $open = $phpcsFile->findNext(T_OPEN_PARENTHESIS, ($use + 1));
                $closeBracket = $tokens[$open]['parenthesis_closer'];

                $prev = $phpcsFile->findPrevious(
                    T_WHITESPACE,
                    ($closeBracket - 1),
                    null,
                    true
                );

                if ($tokens[$closeBracket]['line'] !== $tokens[$tokens[$closeBracket]['parenthesis_opener']]['line']) {
                    if ($tokens[$prev]['line'] === $tokens[$closeBracket]['line']) {
                        $error = 'The closing parenthesis of a multi-line use declaration must be on a new line';
                        $fix = $phpcsFile->addFixableError($error, $closeBracket, 'UseCloseBracketLine');
                        if ($fix === true) {
                            $phpcsFile->fixer->addNewlineBefore($closeBracket);
                        }
                    }
                }
            }
        }

        // Each line between the parenthesis should be indented 4 spaces.
        $openBracket = $tokens[$stackPtr]['parenthesis_opener'];
        $lastLine = $tokens[$openBracket]['line'];
        for ($i = ($openBracket + 1); $i < $closeBracket; $i++) {
            if ($tokens[$i]['line'] !== $lastLine) {
                if ($i === $tokens[$stackPtr]['parenthesis_closer']
                    || ($tokens[$i]['code'] === T_WHITESPACE
                    && (($i + 1) === $closeBracket
                    || ($i + 1) === $tokens[$stackPtr]['parenthesis_closer']))
                ) {
                    // Closing braces need to be indented to the same level
                    // as the function.
                    $expectedIndent = $functionIndent;
                } else {
                    $expectedIndent = ($functionIndent + $this->indent);
                }

                // We changed lines, so this should be a whitespace indent token.
                if ($tokens[$i]['code'] !== T_WHITESPACE) {
                    $foundIndent = 0;
                } elseif ($tokens[$i]['line'] !== $tokens[($i + 1)]['line']) {
                    // This is an empty line, so don't check the indent.
                    $foundIndent = $expectedIndent;

                    $error = 'Blank lines are not allowed in a multi-line function declaration';
                    $fix = $phpcsFile->addFixableError($error, $i, 'EmptyLine');
                    if ($fix === true) {
                        $phpcsFile->fixer->replaceToken($i, '');
                    }
                } else {
                    $foundIndent = strlen($tokens[$i]['content']);
                }

                if ($expectedIndent !== $foundIndent) {
                    $error = 'Multi-line function declaration not indented correctly; expected %s spaces but found %s';
                    $data = [$expectedIndent, $foundIndent];

                    $fix = $phpcsFile->addFixableError($error, $i, 'Indent', $data);
                    if ($fix === true) {
                        $spaces = str_repeat(' ', $expectedIndent);
                        if ($foundIndent === 0) {
                            $phpcsFile->fixer->addContentBefore($i, $spaces);
                        } else {
                            $phpcsFile->fixer->replaceToken($i, $spaces);
                        }
                    }
                }

                $lastLine = $tokens[$i]['line'];
            }

            if ($tokens[$i]['code'] === T_ARRAY || $tokens[$i]['code'] === T_OPEN_SHORT_ARRAY) {
                // Skip arrays as they have their own indentation rules.
                if ($tokens[$i]['code'] === T_OPEN_SHORT_ARRAY) {
                    $i = $tokens[$i]['bracket_closer'];
                } else {
                    $i = $tokens[$i]['parenthesis_closer'];
                }

                $lastLine = $tokens[$i]['line'];
                continue;
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
