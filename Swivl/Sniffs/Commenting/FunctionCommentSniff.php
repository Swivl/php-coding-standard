<?php

namespace Swivl\Sniffs\Commenting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Commenting\DocCommentSniff;
use PHP_CodeSniffer\Standards\Squiz\Sniffs\Commenting\FunctionCommentSniff as SquizFunctionCommentSniff;
use PHP_CodeSniffer\Util\Tokens;
use Swivl\Helpers\FunctionHelper;
use Swivl\Helpers\TypeHelper;

/**
 * FunctionCommentSniff
 *
 * @author Vadim Borodavko <vadim@swivl.com>
 */
class FunctionCommentSniff extends SquizFunctionCommentSniff
{
    private const REQUIRED_PHPDOC_ALWAYS = 'always';
    private const REQUIRED_PHPDOC_BY_MAP = 'map';

    public $requiredPhpdoc = self::REQUIRED_PHPDOC_BY_MAP;

    public $minimumVisibility = 'private';

    /**
     * @var DocCommentSniff
     */
    private $docCommentSniff;

    public function __construct()
    {
        TypeHelper::allowShortScalarTypes();
    }

    /**
     * {@inheritDoc}
     *
     * This method is the copy of the parent method,
     * with the only difference that the condition in which the method "hasTypeWithRequiredComment" is called was added
     */
    public function process(File $phpcsFile, $stackPtr)
    {
        $scopeModifier = $phpcsFile->getMethodProperties($stackPtr)['scope'];
        if (
            ($scopeModifier === 'protected' && $this->minimumVisibility === 'public')
            || (
                $scopeModifier === 'private'
                && ($this->minimumVisibility === 'public' || $this->minimumVisibility === 'protected')
            )
        ) {
            return;
        }

        $tokens = $phpcsFile->getTokens();
        $ignore = Tokens::$methodPrefixes;
        $ignore[] = T_WHITESPACE;

        $commentEnd = $phpcsFile->findPrevious($ignore, ($stackPtr - 1), null, true);
        if ($tokens[$commentEnd]['code'] === T_COMMENT) {
            // Inline comments might just be closing comments for
            // control structures or functions instead of function comments
            // using the wrong comment type. If there is other code on the line,
            // assume they relate to that code.
            $prev = $phpcsFile->findPrevious($ignore, ($commentEnd - 1), null, true);
            if ($prev !== false && $tokens[$prev]['line'] === $tokens[$commentEnd]['line']) {
                $commentEnd = $prev;
            }
        }

        $requireParams = FunctionHelper::isDocCommentParametersRequired($phpcsFile, $stackPtr);
        $requireReturn = FunctionHelper::isDocCommentReturnRequired($phpcsFile, $stackPtr);

        if ($tokens[$commentEnd]['code'] !== T_DOC_COMMENT_CLOSE_TAG && $tokens[$commentEnd]['code'] !== T_COMMENT) {
            if (
                $this->requiredPhpdoc === self::REQUIRED_PHPDOC_ALWAYS
                || (
                    $this->requiredPhpdoc === self::REQUIRED_PHPDOC_BY_MAP
                    && ($requireParams || $requireReturn)
                )
            ) {
                $function = $phpcsFile->getDeclarationName($stackPtr);
                $phpcsFile->addError(
                    'Missing doc comment for function %s()',
                    $stackPtr,
                    'Missing',
                    [$function]
                );
            }

            $phpcsFile->recordMetric($stackPtr, 'Function has doc comment', 'no');

            return;
        }

        $phpcsFile->recordMetric($stackPtr, 'Function has doc comment', 'yes');

        if ($tokens[$commentEnd]['code'] === T_COMMENT) {
            $phpcsFile->addError('You must use "/**" style comments for a function comment', $stackPtr, 'WrongStyle');

            return;
        }

        if ($tokens[$commentEnd]['line'] !== ($tokens[$stackPtr]['line'] - 1)) {
            $error = 'There must be no blank lines after the function comment';
            $phpcsFile->addError($error, $commentEnd, 'SpacingAfter');
        }

        $commentStart = $tokens[$commentEnd]['comment_opener'];

        $hasParams = false;
        $hasReturn = false;
        $hasThrows = false;

        foreach ($tokens[$commentStart]['comment_tags'] as $tag) {
            $tagName = $tokens[$tag]['content'];

            if ($tagName === '@see') {
                // Make sure the tag isn't empty.
                $string = $phpcsFile->findNext(T_DOC_COMMENT_STRING, $tag, $commentEnd);
                if ($string === false || $tokens[$string]['line'] !== $tokens[$tag]['line']) {
                    $error = 'Content missing for @see tag in function comment';
                    $phpcsFile->addError($error, $tag, 'EmptySees');
                }
            } elseif ($tagName === '@param') {
                $hasParams = true;
            } elseif ($tagName === '@return') {
                $hasReturn = true;
            } elseif ($tagName === '@throws') {
                $hasThrows = true;
            }
        }

        $this->processUnknownTags($phpcsFile, $commentStart);

        if ($this->skipIfInheritdoc === true && $this->checkInheritdoc($phpcsFile, $stackPtr, $commentStart)) {
            return;
        }

        $this->processDocComment($phpcsFile, $commentStart);

        if ($hasParams || $requireParams) {
            $this->processParams($phpcsFile, $stackPtr, $commentStart);
        }

        if ($hasReturn || $requireReturn) {
            $this->processReturn($phpcsFile, $stackPtr, $commentStart);
        }

        if ($hasThrows) {
            $this->processThrows($phpcsFile, $stackPtr, $commentStart);
        }
    }

    protected function processUnknownTags(File $phpcsFile, int $commentStart): void
    {
        $tokens = $phpcsFile->getTokens();

        $prevToken = $phpcsFile->findPrevious(T_WHITESPACE, $commentStart - 1, null, true);

        if ($prevToken !== false && $tokens[$prevToken]['code'] !== T_OPEN_CURLY_BRACKET) {
            $padding = $tokens[$commentStart]['line'] - $tokens[$prevToken]['line'] - 1;

            if ($padding !== 1) {
                $error = 'There must be one blank line before the comment';

                if ($phpcsFile->addFixableError($error, $commentStart, 'BlankLineBefore')) {
                    $phpcsFile->fixer->beginChangeset();

                    if ($padding > 1) {
                        for ($i = $prevToken + 1; $i <= $commentStart - 1; $i++) {
                            if ($tokens[$i]['line'] < $tokens[$commentStart]['line'] - 1) {
                                $phpcsFile->fixer->replaceToken($i, '');
                            }
                        }
                    } elseif ($padding === 0) {
                        $commentLineStart = $commentStart;

                        while ($commentLineStart > 0 && $tokens[$commentLineStart - 1]['line'] === $tokens[$commentStart]['line']) {
                            $commentLineStart--;
                        }

                        $phpcsFile->fixer->addNewlineBefore($commentLineStart);
                    }

                    $phpcsFile->fixer->endChangeset();
                }
            }
        }

        $empty = [T_DOC_COMMENT_WHITESPACE, T_DOC_COMMENT_STAR];
        $lastTag = null;
        $lastTagColumn = null;

        foreach ($tokens[$commentStart]['comment_tags'] as $tagStart) {
            $tag = $tokens[$tagStart]['content'];
            $tag = preg_replace("/^@([a-zA-Z0-9\\\\_\x7f-\xff]+).*$/", "@\\1", $tag);
            $tagColumn = $tokens[$tagStart]['column'];

            if ($lastTagColumn !== null && $tagColumn > $lastTagColumn) {
                // Skip nested tags
                continue;
            }

            if ($lastTag !== null && $tag !== $lastTag) {
                if ($prevNotEmptyToken = $phpcsFile->findPrevious($empty, $tagStart - 1, $commentStart, true)) {
                    $padding = $tokens[$tagStart]['line'] - $tokens[$prevNotEmptyToken]['line'] - 1;

                    if ($padding !== 1) {
                        $error = 'There must be one blank line between different tags in the comment';

                        if ($phpcsFile->addFixableError($error, $tagStart, 'BlankLineBetweenTags')) {
                            $phpcsFile->fixer->beginChangeset();

                            for ($i = ($prevNotEmptyToken + 1); $i < $tagStart; $i++) {
                                if ($tokens[$i]['line'] === $tokens[$tagStart]['line']) {
                                    break;
                                }

                                $phpcsFile->fixer->replaceToken($i, '');
                            }

                            $star = $phpcsFile->findPrevious(T_DOC_COMMENT_STAR, $tagStart - 1, $commentStart);
                            $indent = str_repeat(' ', $tokens[$star]['column']);
                            $phpcsFile->fixer->addContent($prevNotEmptyToken, $phpcsFile->eolChar . $indent . '*' . $phpcsFile->eolChar);
                            $phpcsFile->fixer->endChangeset();
                        }
                    }
                }
            }

            $lastTag = $tag;
            $lastTagColumn = $tagColumn;
        }
    }

    protected function processDocComment(File $phpcsFile, int $commentStart): void
    {
        if (!$this->docCommentSniff) {
            $this->docCommentSniff = new DocCommentSniff();
        }

        $this->docCommentSniff->process($phpcsFile, $commentStart);
    }
}
