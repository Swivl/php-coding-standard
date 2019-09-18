<?php

namespace Swivl\Sniffs\Commenting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * FullyQualifiedClassNameUsageSniff
 *
 * Disallows the use of fully qualified class name in PHPDocs and comments.
 *
 * @author Vadim Borodavko <vadim@swivl.com>
 */
class FullyQualifiedClassNameUsageSniff implements Sniff
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
        return [T_DOC_COMMENT_STRING, T_COMMENT];
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
        $content = $tokens[$stackPtr]['content'];
        $fqcnRegexp = "@(\\\\[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*){2,}@";

        if (preg_match($fqcnRegexp, $content, $matches)) {
            $tagStart = $phpcsFile->findPrevious(T_DOC_COMMENT_WHITESPACE, $stackPtr - 1, null, true);
            $tag = ($tagStart && $tokens[$tagStart]['code'] === T_DOC_COMMENT_TAG) ? $tokens[$tagStart]['content'] : '';

            if (preg_match('/@(var|param|return|throws|ORM)/', $tag)) {
                $className = substr($matches[0], 1);
                $error = 'Avoid using fully qualified class name; expected "%s" but found "%s"';
                $data = [ltrim(strrchr($className, "\\"), "\\"), $className];
                $phpcsFile->addError($error, $stackPtr, 'Found', $data);
            }
        }
    }
}
