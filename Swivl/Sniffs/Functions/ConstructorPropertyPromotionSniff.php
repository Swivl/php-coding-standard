<?php

namespace Swivl\Sniffs\Functions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

class ConstructorPropertyPromotionSniff implements Sniff
{
    public $indent = 4;

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
        $methodName = $phpcsFile->getDeclarationName($stackPtr);

        if ($methodName !== '__construct') {
            return;
        }

        $methodParameters = $phpcsFile->getMethodParameters($stackPtr);
        $tokens = $phpcsFile->getTokens();
        $propertyPromotion = false;

        foreach ($methodParameters as $methodParameter) {
            if ($methodParameter['property_visibility'] ?? false) {
                $propertyPromotion = true;

                break;
            }
        }

        if (!$propertyPromotion) {
            return;
        }

        $previousLine = $tokens[$stackPtr]['line'];
        $parametersCount = count($methodParameters);

        foreach ($methodParameters as $paramNum => $methodParameter) {
            $isLastParam = $paramNum === $parametersCount - 1;
            $paramPtr = $methodParameter['token'];
            $paramStartPtr = null;
            $paramEndPtr = null;

            foreach ($methodParameter as $attrName => $attrValue) {
                if (is_int($attrValue) && (substr($attrName, -5) === 'token')) {
                    $paramStartPtr = !$paramStartPtr || $attrValue < $paramStartPtr ? $attrValue : $paramStartPtr;
                    $paramEndPtr = !$paramEndPtr || $attrValue > $paramEndPtr ? $attrValue : $paramEndPtr;
                }
            }

            if ($isLastParam) {
                $paramEndPtr = $phpcsFile->findPrevious(
                    T_WHITESPACE,
                    $tokens[$stackPtr]['parenthesis_closer'] - 1,
                    $paramEndPtr,
                    true
                );
            }

            if ($tokens[$paramPtr]['line'] === $previousLine) {
                $phpcsFile->addFixableError(
                    'Property promotion %s must be multi-line',
                    $paramStartPtr,
                    'MultiLineDeclaration',
                    [$methodParameter['name']]
                );

                $paramIndent = 2 * $this->indent;

                if ($tokens[$paramStartPtr - 1]['code'] === T_WHITESPACE) {
                    $paramIndent -= strlen($tokens[$paramStartPtr - 1]['content']);
                    $paramStartPtr--;
                }

                $phpcsFile->fixer->beginChangeset();

                $phpcsFile->fixer->addContentBefore($paramStartPtr, "\n" . str_repeat(' ', $paramIndent));

                if ($isLastParam) {
                    $comma = $methodParameter['comma_token'] ? '' : ',';

                    $phpcsFile->fixer->addContent($paramEndPtr, $comma . "\n" . str_repeat(' ', $this->indent));
                }

                $phpcsFile->fixer->endChangeset();
            } elseif (!$methodParameter['comma_token']) {
                $phpcsFile->addFixableError(
                    'Missing comma after property promotion parameter %s',
                    $paramPtr,
                    'MissingParameterComma',
                    [$methodParameter['name']]
                );

                $phpcsFile->fixer->beginChangeset();
                $phpcsFile->fixer->addContent($paramEndPtr, ',');
                $phpcsFile->fixer->endChangeset();
            }

            $previousLine = $tokens[$paramPtr]['line'];
        }
    }
}
