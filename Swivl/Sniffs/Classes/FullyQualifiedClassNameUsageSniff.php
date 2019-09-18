<?php

namespace Swivl\Sniffs\Classes;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * FullyQualifiedClassNameUsageSniff
 *
 * Disallows the use of fully qualified class name in the code.
 *
 * @author Vadim Borodavko <vadim@swivl.com>
 */
class FullyQualifiedClassNameUsageSniff implements Sniff
{
    /**
     * The current file being checked.
     *
     * @var string
     */
    protected $currFile = '';

    /**
     * Positions in the stack where errors have checked.
     *
     * @var array
     */
    protected $checkedPos = [];

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
        return [T_NS_SEPARATOR];
    }

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File    $phpcsFile The file being scanned.
     * @param integer $stackPtr  The position of the current token in the stack passed in $tokens.
     */
    public function process(File $phpcsFile, $stackPtr): void
    {
        $file = $phpcsFile->getFilename();
        if ($this->currFile !== $file) {
            $this->checkedPos = [];
            $this->currFile = $file;
        }

        $startPtr = $phpcsFile->findPrevious([T_NS_SEPARATOR, T_STRING], $stackPtr - 1, null, true) + 1;
        if (isset($this->checkedPos[$startPtr])) {
            return;
        }
        $this->checkedPos[$startPtr] = true;

        $prevPtr = $phpcsFile->findPrevious([T_WHITESPACE], $startPtr - 1, null, true);
        $tokens = $phpcsFile->getTokens();

        if ($prevPtr === false || !in_array($tokens[$prevPtr]['code'], [T_USE, T_NAMESPACE], true)) {
            $endPtr = $phpcsFile->findNext([T_NS_SEPARATOR, T_STRING], $stackPtr, null, true);
            $className = $phpcsFile->getTokensAsString($startPtr, $endPtr - $startPtr);

            if (strpos($className, "\\") === 0 && substr_count($className, "\\") > 1) {
                $error = 'Avoid using fully qualified class name; expected "%s" but found "%s"';
                $data = [ltrim(strrchr($className, "\\"), "\\"), $className];
                $phpcsFile->addError($error, $startPtr, 'Found', $data);
            }
        } elseif ($prevPtr !== false && $tokens[$prevPtr]['code'] === T_USE) {
            if ($tokens[$startPtr]['code'] === T_NS_SEPARATOR) {
                $error = 'Avoid using leading slash in the USE statement';

                if ($phpcsFile->addFixableError($error, $startPtr, 'LeadingSlashUse')) {
                    $phpcsFile->fixer->replaceToken($startPtr, '');
                }
            }
        }
    }
}
