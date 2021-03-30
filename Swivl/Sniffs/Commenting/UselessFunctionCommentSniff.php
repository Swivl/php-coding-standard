<?php

namespace Swivl\Sniffs\Commenting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use Swivl\Helpers\DocCommentHelper;
use Swivl\Helpers\FunctionHelper;

class UselessFunctionCommentSniff implements Sniff
{
    public $ignoreClasses = false;

    public $ignoreInterfaces = false;

    public $ignoreTraits = false;

    public $includeNames = [];

    public $excludeNames = [];

    public $maxDescriptionLines = 1;

    /**
     * {@inheritDoc}
     */
    public function register(): array
    {
        return [T_FUNCTION];
    }

    /**
     * {@inheritDoc}
     */
    public function process(File $phpcsFile, $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();

        $methodName = $phpcsFile->getDeclarationName($stackPtr);

        if (
            (count($this->excludeNames) > 0 && in_array($methodName, $this->excludeNames, true))
            || (count($this->includeNames) > 0 && !in_array($methodName, $this->includeNames, true))
        ) {
            return;
        }

        $commentEndPtr = FunctionHelper::getDocCommentEnd($phpcsFile, $stackPtr);

        if ($commentEndPtr === null) {
            return;
        }

        $classPtr = FunctionHelper::getClassPtr($phpcsFile, $stackPtr);

        if (
            $classPtr !== null
            && (
                ($tokens[$classPtr]['code'] === T_CLASS && $this->ignoreClasses)
                || ($tokens[$classPtr]['code'] === T_INTERFACE && $this->ignoreInterfaces)
                || ($tokens[$classPtr]['code'] === T_TRAIT && $this->ignoreTraits)
            )
        ) {
            return;
        }

        $commentStartPtr = DocCommentHelper::getDocCommentStart($phpcsFile, $commentEndPtr);
        $description = DocCommentHelper::getDescription($phpcsFile, $commentStartPtr, $commentEndPtr);

        if (substr_count($description, "\n") + 1 > $this->maxDescriptionLines) {
            return;
        }

        if (FunctionHelper::isDocCommentRequired($phpcsFile, $stackPtr)) {
            return;
        }

        $className = FunctionHelper::getClassName($phpcsFile, $stackPtr) ?? '';
        $methodProperties = $phpcsFile->getMethodProperties($stackPtr);
        $methodParameters = $phpcsFile->getMethodParameters($stackPtr);
        $parametersCount = 0;

        foreach ($tokens[$commentStartPtr]['comment_tags'] as $pos => $tagPtr) {
            $tagName = $tokens[$tagPtr]['content'];
            $annotationContent = '';
            $declarationContent = '';

            if ($tagName === '@param') {
                $parametersCount++;

                if ($tokens[($tagPtr + 2)]['code'] === T_DOC_COMMENT_STRING) {
                    $annotationContent = $tokens[($tagPtr + 2)]['content'];
                    $declarationContent = $methodParameters[$parametersCount - 1]['content'];
                }
            } elseif ($tagName === '@return') {
                if ($tokens[($tagPtr + 2)]['code'] === T_DOC_COMMENT_STRING) {
                    $annotationContent = $tokens[($tagPtr + 2)]['content'];
                    $declarationContent = $methodProperties['return_type'];
                }
            } else {
                // If method contains annotation other than @param or @return - it contains additional information
                return;
            }

            $annotationContent = preg_replace('/\s+/', ' ', $annotationContent);
            $annotationContent = str_replace(['integer', 'boolean'], ['int', 'bool'], $annotationContent);
            $annotationContent = str_replace('self', $className, $annotationContent);
            $declarationContent = str_replace('self', $className, $declarationContent);

            if (strpos($annotationContent, '|null') !== false) {
                $annotationContent = '?' . str_replace('|null', '', $annotationContent);
            }

            $declarationContent = trim(strtok($declarationContent, '='));
            $declarationContent = preg_replace('/^(private|protected|public) /', '', $declarationContent);

            if ($annotationContent !== $declarationContent) {
                return;
            }
        }

        if (
            $phpcsFile->addFixableError(
                'Useless doc comment for function %s()',
                $stackPtr,
                'UselessDocComment',
                [$methodName]
            )
        ) {
            DocCommentHelper::removeDocComment($phpcsFile, $commentStartPtr);
        }
    }
}
