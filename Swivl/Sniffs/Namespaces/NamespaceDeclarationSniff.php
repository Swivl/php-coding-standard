<?php

namespace Swivl\Sniffs\Namespaces;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Standards\PSR2\Sniffs\Namespaces\NamespaceDeclarationSniff as PSR2NamespaceDeclarationSniff;

/**
 * NamespaceDeclarationSniff
 *
 * Ensures namespaces are declared correctly.
 *
 * @author Vadim Borodavko <vadim@swivl.com>
 */
class NamespaceDeclarationSniff extends PSR2NamespaceDeclarationSniff
{
    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File    $phpcsFile The file being scanned.
     * @param integer $stackPtr  The position of the current token in the stack passed in $tokens.
     */
    public function process(File $phpcsFile, $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();

        $prev = $phpcsFile->findPrevious(T_WHITESPACE, $stackPtr - 1, 0, true);
        $padding = $tokens[$stackPtr]['line'] - $tokens[$prev]['line'] - 1;
        if ($padding !== 1) {
            $error = 'There must be one blank line before the namespace declaration';
            if ($phpcsFile->addFixableError($error, $stackPtr, 'BlankLineBefore')) {
                $phpcsFile->fixer->beginChangeset();
                if ($padding > 1) {
                    for ($i = $prev + 1; $i <= $stackPtr - 1; $i++) {
                        if ($tokens[$i]['line'] < $tokens[$stackPtr]['line'] - 1) {
                            $phpcsFile->fixer->replaceToken($i, '');
                        }
                    }
                } elseif ($padding === 0) {
                    $phpcsFile->fixer->addNewlineBefore($stackPtr);
                }
                $phpcsFile->fixer->endChangeset();
            }
        }

        if ($tokens[$prev]['code'] !== T_OPEN_TAG) {
            $error = 'Namespace declaration must be the first statement in the file';
            $phpcsFile->addError($error, $stackPtr, 'MustBeFirstStatement');
        }

        if ($fullPath = $phpcsFile->getFilename()) {
            $startPtr = $phpcsFile->findNext([T_NS_SEPARATOR, T_STRING], $stackPtr + 1);
            $endPtr = $phpcsFile->findNext([T_NS_SEPARATOR, T_STRING], $startPtr + 1, null, true);

            if ($endPtr !== false && pathinfo(pathinfo($fullPath, PATHINFO_DIRNAME), PATHINFO_EXTENSION) !== 'tmp') {
                $namespace = $phpcsFile->getTokensAsString($startPtr, $endPtr - $startPtr);
                $dirname = str_replace(DIRECTORY_SEPARATOR, "\\", pathinfo($fullPath, PATHINFO_DIRNAME));

                if (substr($dirname, -strlen($namespace)) !== $namespace) {
                    $expectedNamespace = $this->determineExpectedNamespace($namespace, $dirname);
                    $error = 'Namespace does not conform PSR-0; expected "%s" but found "%s"';
                    $data = [$expectedNamespace, $namespace];
                    $phpcsFile->addError($error, $stackPtr, 'PSR0', $data);
                }
            }
        }

        parent::process($phpcsFile, $stackPtr);
    }

    /**
     * Determines expected namespace.
     *
     * @param string $namespace Current namespace
     * @param string $dirname   Real file dirname
     *
     * @return string
     */
    protected function determineExpectedNamespace(string $namespace, string $dirname): string
    {
        $namespaceElements = explode("\\", $namespace);
        $dirnameElements = explode("\\", $dirname);
        $dirnameNamespaceStartPos = 0;

        for ($i = count($dirnameElements) - 1; $i >= 0; $i--) {
            if (($matchPos = array_search($dirnameElements[$i], $namespaceElements, true)) !== false) {
                array_splice($namespaceElements, $matchPos);
                $dirnameNamespaceStartPos = $i;
                if ($matchPos === 0) {
                    break;
                }
            }
        }

        return implode("\\", array_slice($dirnameElements, $dirnameNamespaceStartPos));
    }
}
