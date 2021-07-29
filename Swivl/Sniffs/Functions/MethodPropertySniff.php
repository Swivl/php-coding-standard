<?php

namespace Swivl\Sniffs\Functions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

class MethodPropertySniff implements Sniff
{
    /**
     * {@inheritDoc}
     */
    public function register(): array
    {
        return [T_FUNCTION];
    }

    /**
     * @param File $phpcsFile
     * @param int  $stackPtr
     */
    public function process(File $phpcsFile, $stackPtr): void
    {
        $methodParameters = $phpcsFile->getMethodParameters($stackPtr);
        $tokens = $phpcsFile->getTokens();

        foreach ($methodParameters as $methodParameter) {
            if ($methodParameter['comma_token'] !== false) {
                continue;
            }

            $methodParameterToken = $methodParameter['token'];
            $tokenIndex = $methodParameterToken + 1;
            $newLineDefined = false;

            while (
                isset($tokens[$tokenIndex])
                && ($token = $tokens[$tokenIndex])
                && in_array($token['code'], [T_WHITESPACE, T_CLOSE_PARENTHESIS], true)
            ) {
                if (!$newLineDefined) {
                    $newLineDefined = preg_match("/[\n]+/", $token['content']) > 0;
                } else if ($token['code'] === T_CLOSE_PARENTHESIS) {
                    $phpcsFile->addFixableError(
                        'Missing comma after last multiline method parameter %s',
                        $methodParameterToken,
                        'MissingParameterComma',
                        [$methodParameter['name']],
                    );
                }

                $tokenIndex++;
            }
        }
    }
}
