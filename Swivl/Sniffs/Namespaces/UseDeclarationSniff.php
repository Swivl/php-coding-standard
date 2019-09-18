<?php

namespace Swivl\Sniffs\Namespaces;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * UseDeclarationSniff
 *
 * @author Vadim Borodavko <vadim@swivl.com>
 */
class UseDeclarationSniff implements Sniff
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
        return [T_NAMESPACE];
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

        $uses = [];
        $usePtr = $stackPtr;
        $lastUsePtr = null;

        while ($usePtr = $phpcsFile->findNext([T_USE, T_CLASS, T_INTERFACE, T_TRAIT], $usePtr + 1)) {
            if ($tokens[$usePtr]['code'] !== T_USE) {
                break;
            }

            if ($nsStartPtr = $phpcsFile->findNext([T_NS_SEPARATOR, T_STRING], $usePtr + 1)) {
                if ($nsEndPtr = $phpcsFile->findNext([T_NS_SEPARATOR, T_STRING], $nsStartPtr + 1, null, true)) {
                    $namespace = $phpcsFile->getTokensAsString($nsStartPtr, $nsEndPtr - $nsStartPtr);
                    $uses[$nsStartPtr] = $namespace;

                    if ($lastUsePtr !== null) {
                        $padding = $tokens[$usePtr]['line'] - $tokens[$lastUsePtr]['line'];
                        if ($padding === 0) {
                            $error = 'Each USE statement must be on a line by itself';
                            $phpcsFile->addError($error, $usePtr, 'SameLine');
                        } elseif ($padding > 1) {
                            $error = 'There must be no blank lines between USE statements';
                            if ($phpcsFile->addFixableError($error, $usePtr, 'BlankLineBetween')) {
                                $phpcsFile->fixer->beginChangeset();

                                for ($i = $usePtr - 1; $i > $lastUsePtr; $i--) {
                                    if ($tokens[$i]['code'] !== T_WHITESPACE) {
                                        break;
                                    }

                                    if ($tokens[$i]['line'] < $tokens[$usePtr]['line']) {
                                        $phpcsFile->fixer->replaceToken($i, '');
                                    }
                                }

                                $phpcsFile->fixer->endChangeset();
                            }
                        }
                    }

                    $lastUsePtr = $usePtr;
                }
            }
        }

        $orderedUses = array_values($uses);
        sort($orderedUses, SORT_STRING | SORT_FLAG_CASE);
        $replacements = [];

        foreach ($uses as $ptr => $use) {
            $orderedUse = current($orderedUses);
            next($orderedUses);

            if ($orderedUse !== $use) {
                $error = 'USE statements must be ordered; here should be %s';
                $data = [$orderedUse];

                if ($phpcsFile->addFixableError($error, $ptr, 'Unordered', $data)) {
                    $replacements[$ptr] = $orderedUse;
                }
            }
        }

        if (count($replacements) > 0) {
            $phpcsFile->fixer->beginChangeset();

            foreach ($replacements as $ptr => $use) {
                $nsEndPtr = $phpcsFile->findNext([T_NS_SEPARATOR, T_STRING], $ptr + 1, null, true);

                for ($i = $ptr + 1; $i < $nsEndPtr; $i++) {
                    $phpcsFile->fixer->replaceToken($i, '');
                }

                $phpcsFile->fixer->replaceToken($ptr, $use);
            }

            $phpcsFile->fixer->endChangeset();
        }
    }
}
