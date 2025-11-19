<?php

namespace Swivl\Sniffs\Commenting;

use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Commenting\DocCommentSniff;
use PHP_CodeSniffer\Standards\Squiz\Sniffs\Commenting\FunctionCommentSniff as SquizFunctionCommentSniff;
use PHP_CodeSniffer\Util\Tokens;
use Swivl\Helpers\Common;
use Swivl\Helpers\FunctionHelper;

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

    /**
     * @var integer|string|null
     */
    private $phpVersion = null;

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
        $ignore = Tokens::METHOD_MODIFIERS;
        $ignore[T_WHITESPACE] = T_WHITESPACE;

        for ($commentEnd = ($stackPtr - 1); $commentEnd >= 0; $commentEnd--) {
            if (isset($ignore[$tokens[$commentEnd]['code']]) === true) {
                continue;
            }

            if (
                $tokens[$commentEnd]['code'] === T_ATTRIBUTE_END
                && isset($tokens[$commentEnd]['attribute_opener']) === true
            ) {
                $commentEnd = $tokens[$commentEnd]['attribute_opener'];
                continue;
            }

            break;
        }

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
            for ($i = ($commentEnd + 1); $i < $stackPtr; $i++) {
                if (isset($tokens[$i]['attribute_closer']) === true) {
                    $i = $tokens[$i]['attribute_closer'];
                    continue;
                }

                if (
                    $tokens[$i]['column'] !== 1
                    || $tokens[$i]['code'] !== T_WHITESPACE
                    || $tokens[$i]['line'] === $tokens[($i + 1)]['line']
                    || isset(Tokens::PHPCS_ANNOTATION_TOKENS[$tokens[($i - 1)]['code']]) === true
                ) {
                    continue;
                }

                $nextNonWhitespace = $phpcsFile->findNext(T_WHITESPACE, ($i + 1), null, true);
                $error = 'There must be no blank lines between the function comment and the declaration';
                $fix = $phpcsFile->addFixableError($error, $i, 'SpacingAfter');

                if ($fix === true) {
                    $phpcsFile->fixer->beginChangeset();

                    for ($j = $i; $j < $nextNonWhitespace; $j++) {
                        if ($tokens[$j]['line'] === $tokens[$nextNonWhitespace]['line']) {
                            break;
                        }

                        $phpcsFile->fixer->replaceToken($j, '');
                    }

                    $phpcsFile->fixer->endChangeset();
                }

                $i = $nextNonWhitespace;
            }
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

    /**
     * {@inheritDoc}
     *
     * This method is the copy of the parent method, with the only difference that we use custom Common service
     */
    protected function processReturn(File $phpcsFile, int $stackPtr, int $commentStart)
    {
        $tokens = $phpcsFile->getTokens();
        $return = null;

        if ($this->skipIfInheritdoc === true) {
            if ($this->checkInheritdoc($phpcsFile, $stackPtr, $commentStart) === true) {
                return;
            }
        }

        foreach ($tokens[$commentStart]['comment_tags'] as $tag) {
            if ($tokens[$tag]['content'] === '@return') {
                if ($return !== null) {
                    $error = 'Only 1 @return tag is allowed in a function comment';
                    $phpcsFile->addError($error, $tag, 'DuplicateReturn');

                    return;
                }

                $return = $tag;
            }
        }

        // Skip constructor and destructor.
        $methodName = $phpcsFile->getDeclarationName($stackPtr);
        $isSpecialMethod = in_array($methodName, $this->specialMethods, true);

        if ($return !== null) {
            $content = $tokens[($return + 2)]['content'];
            if (empty($content) === true || $tokens[($return + 2)]['code'] !== T_DOC_COMMENT_STRING) {
                $error = 'Return type missing for @return tag in function comment';
                $phpcsFile->addError($error, $return, 'MissingReturnType');
            } else {
                // Support both a return type and a description.
                preg_match('`^((?:\|?(?:array\([^\)]*\)|[\\\\a-z0-9_\[\]]+))*)( .*)?`i', $content, $returnParts);
                if (isset($returnParts[1]) === false) {
                    return;
                }

                $returnType = $returnParts[1];

                // Check return type (can be multiple, separated by '|').
                $typeNames = explode('|', $returnType);
                $suggestedNames = [];
                foreach ($typeNames as $typeName) {
                    $suggestedName = Common::suggestType($typeName);
                    if (in_array($suggestedName, $suggestedNames, true) === false) {
                        $suggestedNames[] = $suggestedName;
                    }
                }

                $suggestedType = implode('|', $suggestedNames);
                if ($returnType !== $suggestedType) {
                    $error = 'Expected "%s" but found "%s" for function return type';
                    $data  = [
                        $suggestedType,
                        $returnType,
                    ];
                    $fix   = $phpcsFile->addFixableError($error, $return, 'InvalidReturn', $data);
                    if ($fix === true) {
                        $replacement = $suggestedType;
                        if (empty($returnParts[2]) === false) {
                            $replacement .= $returnParts[2];
                        }

                        $phpcsFile->fixer->replaceToken(($return + 2), $replacement);
                        unset($replacement);
                    }
                }

                // If the return type is void, make sure there is
                // no return statement in the function.
                if ($returnType === 'void') {
                    if (isset($tokens[$stackPtr]['scope_closer']) === true) {
                        $endToken = $tokens[$stackPtr]['scope_closer'];
                        for ($returnToken = $stackPtr; $returnToken < $endToken; $returnToken++) {
                            if (
                                $tokens[$returnToken]['code'] === T_CLOSURE
                                || $tokens[$returnToken]['code'] === T_ANON_CLASS
                            ) {
                                $returnToken = $tokens[$returnToken]['scope_closer'];

                                continue;
                            }

                            if (
                                $tokens[$returnToken]['code'] === T_RETURN
                                || $tokens[$returnToken]['code'] === T_YIELD
                                || $tokens[$returnToken]['code'] === T_YIELD_FROM
                            ) {
                                break;
                            }
                        }

                        if ($returnToken !== $endToken) {
                            // If the function is not returning anything, just
                            // exiting, then there is no problem.
                            $semicolon = $phpcsFile->findNext(T_WHITESPACE, ($returnToken + 1), null, true);
                            if ($tokens[$semicolon]['code'] !== T_SEMICOLON) {
                                $error = 'Function return type is void, but function contains return statement';
                                $phpcsFile->addError($error, $return, 'InvalidReturnVoid');
                            }
                        }
                    }
                } elseif (
                    $returnType !== 'mixed'
                    && $returnType !== 'never'
                    && in_array('void', $typeNames, true) === false
                ) {
                    // If return type is not void, never, or mixed, there needs to be a
                    // return statement somewhere in the function that returns something.
                    if (isset($tokens[$stackPtr]['scope_closer']) === true) {
                        $endToken = $tokens[$stackPtr]['scope_closer'];
                        for ($returnToken = $stackPtr; $returnToken < $endToken; $returnToken++) {
                            if (
                                $tokens[$returnToken]['code'] === T_CLOSURE
                                || $tokens[$returnToken]['code'] === T_ANON_CLASS
                            ) {
                                $returnToken = $tokens[$returnToken]['scope_closer'];

                                continue;
                            }

                            if (
                                $tokens[$returnToken]['code'] === T_RETURN
                                || $tokens[$returnToken]['code'] === T_YIELD
                                || $tokens[$returnToken]['code'] === T_YIELD_FROM
                            ) {
                                break;
                            }
                        }

                        if ($returnToken === $endToken) {
                            $error = 'Function return type is not void, but function has no return statement';
                            $phpcsFile->addError($error, $return, 'InvalidNoReturn');
                        } else {
                            $semicolon = $phpcsFile->findNext(T_WHITESPACE, ($returnToken + 1), null, true);
                            if ($tokens[$semicolon]['code'] === T_SEMICOLON) {
                                $error = 'Function return type is not void, but function is returning void here';
                                $phpcsFile->addError($error, $returnToken, 'InvalidReturnNotVoid');
                            }
                        }
                    }
                }
            }
        } else {
            if ($isSpecialMethod === true) {
                return;
            }

            $error = 'Missing @return tag in function comment';
            $phpcsFile->addError($error, $tokens[$commentStart]['comment_closer'], 'MissingReturn');
        }
    }

    /**
     * {@inheritDoc}
     *
     * This method is the copy of the parent method, with the only difference that we use custom Common service
     */
    protected function processParams(File $phpcsFile, int $stackPtr, int $commentStart)
    {
        if ($this->phpVersion === null) {
            $this->phpVersion = Config::getConfigData('php_version');
            if ($this->phpVersion === null) {
                $this->phpVersion = PHP_VERSION_ID;
            }
        }

        $tokens = $phpcsFile->getTokens();

        if ($this->skipIfInheritdoc === true) {
            if ($this->checkInheritdoc($phpcsFile, $stackPtr, $commentStart) === true) {
                return;
            }
        }

        $params = [];
        $maxType = 0;
        $maxVar = 0;
        foreach ($tokens[$commentStart]['comment_tags'] as $pos => $tag) {
            if ($tokens[$tag]['content'] !== '@param') {
                continue;
            }

            $type = '';
            $typeSpace = 0;
            $var = '';
            $varSpace = 0;
            $comment = '';
            $commentLines = [];
            if ($tokens[($tag + 2)]['code'] === T_DOC_COMMENT_STRING) {
                $matches = [];
                preg_match('/((?:(?![$.]|&(?=\$)).)*)(?:((?:\.\.\.)?(?:\$|&)[^\s]+)(?:(\s+)(.*))?)?/', $tokens[($tag + 2)]['content'], $matches);

                if (empty($matches) === false) {
                    $typeLen = strlen($matches[1]);
                    $type = trim($matches[1]);
                    $typeSpace = ($typeLen - strlen($type));
                    $typeLen = strlen($type);
                    if ($typeLen > $maxType) {
                        $maxType = $typeLen;
                    }
                }

                if ($tokens[($tag + 2)]['content'][0] === '$') {
                    $error = 'Missing parameter type';
                    $phpcsFile->addError($error, $tag, 'MissingParamType');
                } elseif (isset($matches[2]) === true) {
                    $var = $matches[2];
                    $varLen = strlen($var);
                    if ($varLen > $maxVar) {
                        $maxVar = $varLen;
                    }

                    if (isset($matches[4]) === true) {
                        $varSpace       = strlen($matches[3]);
                        $comment        = $matches[4];
                        $commentLines[] = [
                            'comment' => $comment,
                            'token' => ($tag + 2),
                            'indent' => $varSpace,
                        ];

                        // Any strings until the next tag belong to this comment.
                        if (isset($tokens[$commentStart]['comment_tags'][($pos + 1)]) === true) {
                            $end = $tokens[$commentStart]['comment_tags'][($pos + 1)];
                        } else {
                            $end = $tokens[$commentStart]['comment_closer'];
                        }

                        for ($i = ($tag + 3); $i < $end; $i++) {
                            if ($tokens[$i]['code'] === T_DOC_COMMENT_STRING) {
                                $indent = 0;
                                if ($tokens[($i - 1)]['code'] === T_DOC_COMMENT_WHITESPACE) {
                                    $indent = $tokens[($i - 1)]['length'];
                                }

                                $comment .= ' ' . $tokens[$i]['content'];
                                $commentLines[] = [
                                    'comment' => $tokens[$i]['content'],
                                    'token' => $i,
                                    'indent' => $indent,
                                ];
                            }
                        }
                    } else {
                        $error = 'Missing parameter comment';
                        $phpcsFile->addError($error, $tag, 'MissingParamComment');
                        $commentLines[] = ['comment' => ''];
                    }
                } else {
                    $error = 'Missing parameter name';
                    $phpcsFile->addError($error, $tag, 'MissingParamName');
                }
            } else {
                $error = 'Missing parameter type';
                $phpcsFile->addError($error, $tag, 'MissingParamType');
            }

            $params[] = [
                'tag' => $tag,
                'type' => $type,
                'var' => $var,
                'comment' => $comment,
                'commentLines' => $commentLines,
                'type_space' => $typeSpace,
                'var_space' => $varSpace,
            ];
        }

        $realParams  = $phpcsFile->getMethodParameters($stackPtr);
        $foundParams = [];

        // We want to use ... for all variable length arguments, so added
        // this prefix to the variable name so comparisons are easier.
        foreach ($realParams as $pos => $param) {
            if ($param['variable_length'] === true) {
                $realParams[$pos]['name'] = '...' . $realParams[$pos]['name'];
            }
        }

        foreach ($params as $pos => $param) {
            // If the type is empty, the whole line is empty.
            if ($param['type'] === '') {
                continue;
            }

            // Check the param type value.
            $typeNames = explode('|', $param['type']);
            $suggestedTypeNames = [];

            foreach ($typeNames as $typeName) {
                if ($typeName === '') {
                    continue;
                }

                // Strip nullable operator.
                if ($typeName[0] === '?') {
                    $typeName = substr($typeName, 1);
                }

                $suggestedName = Common::suggestType($typeName);
                $suggestedTypeNames[] = $suggestedName;

                if (count($typeNames) > 1) {
                    continue;
                }

                // Check type hint for array and custom type.
                $suggestedTypeHint = '';
                if (strpos($suggestedName, 'array') !== false || substr($suggestedName, -2) === '[]') {
                    $suggestedTypeHint = 'array';
                } elseif (strpos($suggestedName, 'callable') !== false) {
                    $suggestedTypeHint = 'callable';
                } elseif (strpos($suggestedName, 'callback') !== false) {
                    $suggestedTypeHint = 'callable';
                } elseif (isset(Common::ALLOWED_TYPES[$suggestedName]) === false) {
                    $suggestedTypeHint = $suggestedName;
                }

                if ($this->phpVersion >= 70000) {
                    if ($suggestedName === 'string') {
                        $suggestedTypeHint = 'string';
                    } elseif ($suggestedName === 'int' || $suggestedName === 'integer') {
                        $suggestedTypeHint = 'int';
                    } elseif ($suggestedName === 'float') {
                        $suggestedTypeHint = 'float';
                    } elseif ($suggestedName === 'bool' || $suggestedName === 'boolean') {
                        $suggestedTypeHint = 'bool';
                    }
                }

                if ($this->phpVersion >= 70200) {
                    if ($suggestedName === 'object') {
                        $suggestedTypeHint = 'object';
                    }
                }

                if ($this->phpVersion >= 80000) {
                    if ($suggestedName === 'mixed') {
                        $suggestedTypeHint = 'mixed';
                    }
                }

                if ($suggestedTypeHint !== '' && isset($realParams[$pos]) === true && $param['var'] !== '') {
                    $typeHint = $realParams[$pos]['type_hint'];

                    // Remove namespace prefixes when comparing.
                    $compareTypeHint = substr($suggestedTypeHint, (strlen($typeHint) * -1));

                    if ($typeHint === '') {
                        $error = 'Type hint "%s" missing for %s';
                        $data  = [
                            $suggestedTypeHint,
                            $param['var'],
                        ];

                        $errorCode = 'TypeHintMissing';
                        if (
                            $suggestedTypeHint === 'string'
                            || $suggestedTypeHint === 'int'
                            || $suggestedTypeHint === 'float'
                            || $suggestedTypeHint === 'bool'
                        ) {
                            $errorCode = 'Scalar' . $errorCode;
                        }

                        $phpcsFile->addError($error, $stackPtr, $errorCode, $data);
                    } elseif ($typeHint !== $compareTypeHint && $typeHint !== '?' . $compareTypeHint) {
                        $error = 'Expected type hint "%s"; found "%s" for %s';
                        $data  = [
                            $suggestedTypeHint,
                            $typeHint,
                            $param['var'],
                        ];
                        $phpcsFile->addError($error, $stackPtr, 'IncorrectTypeHint', $data);
                    }
                } elseif ($suggestedTypeHint === '' && isset($realParams[$pos]) === true) {
                    $typeHint = $realParams[$pos]['type_hint'];
                    if ($typeHint !== '') {
                        $error = 'Unknown type hint "%s" found for %s';
                        $data  = [
                            $typeHint,
                            $param['var'],
                        ];
                        $phpcsFile->addError($error, $stackPtr, 'InvalidTypeHint', $data);
                    }
                }
            }

            $suggestedType = implode('|', $suggestedTypeNames);
            if ($param['type'] !== $suggestedType) {
                $error = 'Expected "%s" but found "%s" for parameter type';
                $data = [
                    $suggestedType,
                    $param['type'],
                ];

                $fix = $phpcsFile->addFixableError($error, $param['tag'], 'IncorrectParamVarName', $data);
                if ($fix === true) {
                    $phpcsFile->fixer->beginChangeset();

                    $content = $suggestedType;
                    $content .= str_repeat(' ', $param['type_space']);
                    $content .= $param['var'];
                    $content .= str_repeat(' ', $param['var_space']);
                    if (isset($param['commentLines'][0]) === true) {
                        $content .= $param['commentLines'][0]['comment'];
                    }

                    $phpcsFile->fixer->replaceToken(($param['tag'] + 2), $content);

                    // Fix up the indent of additional comment lines.
                    foreach ($param['commentLines'] as $lineNum => $line) {
                        if ($lineNum === 0 || $param['commentLines'][$lineNum]['indent'] === 0) {
                            continue;
                        }

                        $diff = (strlen($param['type']) - strlen($suggestedType));
                        $newIndent = ($param['commentLines'][$lineNum]['indent'] - $diff);
                        $phpcsFile->fixer->replaceToken(
                            ($param['commentLines'][$lineNum]['token'] - 1),
                            str_repeat(' ', $newIndent)
                        );
                    }

                    $phpcsFile->fixer->endChangeset();
                }
            }

            if ($param['var'] === '') {
                continue;
            }

            $foundParams[] = $param['var'];

            // Check number of spaces after the type.
            $this->checkSpacingAfterParamType($phpcsFile, $param, $maxType);

            // Make sure the param name is correct.
            if (isset($realParams[$pos]) === true) {
                $realName     = $realParams[$pos]['name'];
                $paramVarName = $param['var'];

                if ($param['var'][0] === '&') {
                    // Even when passed by reference, the variable name in $realParams does not have
                    // a leading '&'. This sniff will accept both '&$var' and '$var' in these cases.
                    $paramVarName = substr($param['var'], 1);

                    // This makes sure that the 'MissingParamTag' check won't throw a false positive.
                    $foundParams[(count($foundParams) - 1)] = $paramVarName;

                    if ($realParams[$pos]['pass_by_reference'] !== true && $realName === $paramVarName) {
                        // Don't complain about this unless the param name is otherwise correct.
                        $error = 'Doc comment for parameter %s is prefixed with "&" but parameter is not passed by reference';
                        $code  = 'ParamNameUnexpectedAmpersandPrefix';
                        $data  = [$paramVarName];

                        // We're not offering an auto-fix here because we can't tell if the docblock
                        // is wrong, or the parameter should be passed by reference.
                        $phpcsFile->addError($error, $param['tag'], $code, $data);
                    }
                }

                if ($realName !== $paramVarName) {
                    $code = 'ParamNameNoMatch';
                    $data = [
                        $paramVarName,
                        $realName,
                    ];

                    $error = 'Doc comment for parameter %s does not match ';
                    if (strtolower($paramVarName) === strtolower($realName)) {
                        $error .= 'case of ';
                        $code   = 'ParamNameNoCaseMatch';
                    }

                    $error .= 'actual variable name %s';

                    $phpcsFile->addError($error, $param['tag'], $code, $data);
                }
            } elseif (substr($param['var'], -4) !== ',...') {
                // We must have an extra parameter comment.
                $error = 'Superfluous parameter comment';
                $phpcsFile->addError($error, $param['tag'], 'ExtraParamComment');
            }

            if ($param['comment'] === '') {
                continue;
            }

            // Check number of spaces after the var name.
            $this->checkSpacingAfterParamName($phpcsFile, $param, $maxVar);

            // Param comments must start with a capital letter and end with a full stop.
            if (preg_match('/^(\p{Ll}|\P{L})/u', $param['comment']) === 1) {
                $error = 'Parameter comment must start with a capital letter';
                $phpcsFile->addError($error, $param['tag'], 'ParamCommentNotCapital');
            }

            $lastChar = substr($param['comment'], -1);
            if ($lastChar !== '.') {
                $error = 'Parameter comment must end with a full stop';
                $phpcsFile->addError($error, $param['tag'], 'ParamCommentFullStop');
            }
        }

        $realNames = [];
        foreach ($realParams as $realParam) {
            $realNames[] = $realParam['name'];
        }

        // Report missing comments.
        $diff = array_diff($realNames, $foundParams);
        foreach ($diff as $neededParam) {
            $error = 'Doc comment for parameter "%s" missing';
            $data  = [$neededParam];
            $phpcsFile->addError($error, $commentStart, 'MissingParamTag', $data);
        }
    }
}
