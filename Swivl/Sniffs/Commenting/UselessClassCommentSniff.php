<?php

namespace Swivl\Sniffs\Commenting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use Swivl\Helpers\DocCommentHelper;
use Swivl\Helpers\FunctionHelper;

class UselessClassCommentSniff implements Sniff
{
    public $maxDescriptionLines = 1;

    public $uselessTags = [
        '@package',
    ];

    public $uselessDescriptions = [
        'Doctrine migration',
    ];

    /**
     * {@inheritDoc}
     */
    public function register(): array
    {
        return [
            T_CLASS,
            T_INTERFACE,
            T_TRAIT,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function process(File $phpcsFile, $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();

        $commentEndPtr = FunctionHelper::getDocCommentEnd($phpcsFile, $stackPtr);

        if ($commentEndPtr === null) {
            return;
        }

        $commentStartPtr = DocCommentHelper::getDocCommentStart($phpcsFile, $commentEndPtr);
        $description = rtrim(DocCommentHelper::getDescription($phpcsFile, $commentStartPtr, $commentEndPtr), '.');

        if (substr_count($description, "\n") + 1 > $this->maxDescriptionLines) {
            return;
        }

        $className = $phpcsFile->getDeclarationName($stackPtr);
        $uselessRegexp = sprintf('/^(class|interface|trait)?\s*%s/i', preg_quote($className, '/'));

        if (
            !preg_match($uselessRegexp, $description)
            && !in_array($description, $this->uselessDescriptions, true)
        ) {
            return;
        }

        foreach ($tokens[$commentStartPtr]['comment_tags'] as $tagPtr) {
            if (!in_array($tokens[$tagPtr]['content'], $this->uselessTags, true)) {
                return;
            }
        }

        if (
            $phpcsFile->addFixableError(
                'Useless doc comment for class %s',
                $stackPtr,
                'UselessDocComment',
                [$className]
            )
        ) {
            DocCommentHelper::removeDocComment($phpcsFile, $commentStartPtr);
        }
    }
}
