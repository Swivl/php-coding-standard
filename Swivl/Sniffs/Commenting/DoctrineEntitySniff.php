<?php

namespace Swivl\Sniffs\Commenting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\AbstractVariableSniff;
use PHP_CodeSniffer\Util\Common;

/**
 * DoctrineEntitySniff
 *
 * @author Vadim Borodavko <vadim@swivl.com>
 */
class DoctrineEntitySniff extends AbstractVariableSniff
{
    private const CLASS_SUFFIX = '::class';
    private const CLASS_SUFFIX_LENGTH = 7;

    protected const SCALAR_TYPES = [
        'bool' => 'boolean',
        'int' => 'integer',
    ];

    /**
     * Support of base class typehinting (adder, setter, remover), but using concrete type in association definition
     *
     * @var string[]
     */
    public $concreteTypeToBaseTypeMap = [];

    /**
     * Try to dynamically calculate mapped enum type for enum column
     *
     * @var boolean
     */
    public $useDynamicalCalculationForEnumColumnType = false;

    /**
     * Doctrine annotations reference.
     *
     * @var array
     */
    protected $reference = [
        'Column' => [
            'required' => ['type'],
            'attributes' => [
                'name' => 'string',
                'type' => 'string',
                'length' => 'integer',
                'precision' => 'integer',
                'scale' => 'integer',
                'unique' => 'boolean',
                'nullable' => 'boolean',
                'enumType' => 'class',
                'options' => 'array',
                'columnDefinition' => 'string',
            ],
        ],
        'Cache' => [
            'attributes' => [
                'usage' => ['READ_ONLY', 'READ_WRITE', 'NONSTRICT_READ_WRITE'],
                'region' => 'string',
            ],
        ],
        'GeneratedValue' => [
            'requires' => ['Id'],
            'attributes' => [
                'strategy' => ['AUTO', 'SEQUENCE', 'TABLE', 'IDENTITY', 'UUID', 'CUSTOM', 'NONE'],
            ],
        ],
        'Id' => [],
        'JoinColumn' => [
            'requires' => [['ManyToOne', 'OneToOne']],
            'attributes' => [
                'name' => 'string',
                'referencedColumnName' => 'string',
                'unique' => 'boolean',
                'nullable' => 'boolean',
                'onDelete' => ['SET NULL', 'CASCADE'],
                'columnDefinition' => 'string',
            ],
        ],
        'JoinTable' => [
            'requires' => [['OneToMany', 'ManyToMany']],
            'attributes' => [
                'name' => 'string',
                'joinColumns' => 'array',
                'inverseJoinColumns' => 'array',
            ],
        ],
        'ManyToOne' => [
            'required' => ['targetEntity'],
            'attributes' => [
                'targetEntity' => 'class',
                'cascade' => 'string',
                'fetch' => ['LAZY', 'EAGER'],
                'inversedBy' => 'string',
            ],
        ],
        'ManyToMany' => [
            'required' => ['targetEntity'],
            'attributes' => [
                'targetEntity' => 'class',
                'mappedBy' => 'string',
                'inversedBy' => 'string',
                'cascade' => 'string',
                'fetch' => ['LAZY', 'EXTRA_LAZY', 'EAGER'],
                'indexBy' => 'string',
            ],
        ],
        'OneToOne' => [
            'required' => ['targetEntity'],
            'attributes' => [
                'targetEntity' => 'class',
                'cascade' => 'string',
                'fetch' => ['LAZY', 'EAGER'],
                'orphanRemoval' => 'boolean',
                'mappedBy' => 'string',
                'inversedBy' => 'string',
            ],
        ],
        'OneToMany' => [
            'required' => ['targetEntity'],
            'attributes' => [
                'targetEntity' => 'class',
                'cascade' => 'string',
                'orphanRemoval' => 'boolean',
                'mappedBy' => 'string',
                'fetch' => ['LAZY', 'EXTRA_LAZY', 'EAGER'],
                'indexBy' => 'string',
            ],
        ],
        'OrderBy' => [
            'requires' => [['ManyToMany', 'OneToMany']],
        ],
        'SequenceGenerator' => [
            'requires' => ['GeneratedValue'],
            'required' => ['sequenceName'],
            'attributes' => [
                'sequenceName' => 'string',
                'allocationSize' => 'integer',
                'initialValue' => 'integer',
            ],
        ],
    ];

    /**
     * Doctrine mapping types.
     *
     * @var array
     */
    protected $mappingTypes = [
        'string' => 'string',
        'integer' => 'integer',
        'smallint' => 'integer',
        'tinyint' => 'integer',
        'bigint' => 'integer',
        'boolean' => 'boolean',
        'decimal' => 'float',
        'date' => 'DateTime',
        'time' => 'DateTime',
        'datetime' => 'DateTime',
        'datetimetz' => 'DateTime',
        'text' => 'string',
        'object' => '',
        'array' => 'array',
        'simple_array' => 'array',
        'json_array' => 'array',
        'json' => 'array',
        'float' => 'float',
        'guid' => 'string',
    ];

    /**
     * The current file being checked.
     *
     * @var string
     */
    protected $currFile = '';

    /**
     * A list of all doc-comment tags.
     *
     * @var array
     */
    protected $tags = [];

    /**
     * @var File
     */
    protected $phpcsFile;

    /**
     * Pointer to the current doc-comment annotation tag.
     *
     * @var integer
     */
    protected $tagStart;

    /**
     * Pointer to the current class member.
     *
     * @var integer
     */
    protected $memberPtr;

    /**
     * @var string
     */
    protected $memberType;

    /**
     * Current class member type.
     *
     * @var string
     */
    protected $varType;

    /**
     * Current class member name.
     *
     * @var string
     */
    protected $varName;

    /**
     * A map of all class methods in format Pointer=>MethodName.
     *
     * @var string[]
     */
    protected $methods;

    /**
     * Current class name.
     *
     * @var string
     */
    protected $className;

    /**
     * A map of all class members initialized in the class constructor in format MemberName=>Value.
     *
     * @var string[]
     */
    protected $initializedMembers;

    /**
     * @var array
     */
    protected $codingStandardsIgnoreErrors = [];

    /**
     * Called to process class member vars.
     *
     * @param File    $phpcsFile The PHP_CodeSniffer file where this token was found.
     * @param integer $stackPtr  The position where the token was found.
     */
    protected function processMemberVar(File $phpcsFile, $stackPtr): void
    {
        $this->phpcsFile = $phpcsFile;
        $this->memberPtr = $stackPtr;
        $this->varType = null;
        $this->memberType = null;
        $this->tags = [];

        $this->parsePropertyAnnotations($phpcsFile, $stackPtr);
        $this->parsePropertyAttributes($phpcsFile, $stackPtr);

        if (count($this->tags) === 0) {
            return;
        }

        $memberProperties = $phpcsFile->getMemberProperties($stackPtr);
        $this->memberType = $memberProperties['type'];

        foreach ($this->tags[1] as $tagPos => $tagName) {
            $this->tagStart = $tagPos;

            $this->processORMAnnotation($tagName, $this->tags[2][$tagPos]);
        }
    }

    /**
     * Called to process normal member vars.
     *
     * @param File    $phpcsFile The PHP_CodeSniffer file where this token was found.
     * @param integer $stackPtr  The position where the token was found.
     */
    protected function processVariable(File $phpcsFile, $stackPtr): void
    {
    }

    /**
     * Called to process variables found in double quoted strings or heredocs.
     *
     * Note that there may be more than one variable in the string, which will
     * result only in one call for the string or one call per line for heredocs.
     *
     * @param File    $phpcsFile The PHP_CodeSniffer file where this token was found.
     * @param integer $stackPtr  The position where the double quoted string was found.
     */
    protected function processVariableInString(File $phpcsFile, $stackPtr): void
    {
    }

    protected function parsePropertyAnnotations(File $phpcsFile, int $propertyPtr): void
    {
        $commentEnd = $phpcsFile->findPrevious(T_DOC_COMMENT_CLOSE_TAG, $propertyPtr - 1);

        if ($commentEnd === false) {
            return;
        }

        $commentFor = $phpcsFile->findNext([T_VARIABLE, T_CLASS, T_INTERFACE, T_FUNCTION], $commentEnd + 1);

        if ($commentFor !== $propertyPtr) {
            return;
        }

        $commentStart = $phpcsFile->findPrevious(T_DOC_COMMENT_OPEN_TAG, $commentEnd - 1);

        $commentString = $phpcsFile->getTokensAsString($commentStart + 1, ($commentEnd - $commentStart - 1));
        $commentLines = explode($phpcsFile->eolChar, $commentString);
        $comment = '';

        foreach ($commentLines as $lineNum => $line) {
            $line = trim($line, "/* \r\n");
            $comment .= ' ' . $line;

            if (preg_match('/^@var /', $line)) {
                $definition = preg_split('/\s+/', $line);

                if (count($definition) > 1) {
                    $this->varType = strtok($definition[1], '|');
                }
            }
        }

        if (!preg_match_all('/ @([a-zA-Z0-9_\x7f-\xff\\\\]+)(\([^\)]*\))?/', $comment, $matches, PREG_PATTERN_ORDER)) {
            return;
        }

        $this->parseCodingStandardsIgnoreErrors($comment);

        $tokens = $phpcsFile->getTokens();

        foreach ($matches[1] as $tagPos => $tagName) {
            if (strpos($tagName, 'ORM') === 0) {
                $commentTags = $tokens[$commentStart]['comment_tags'];
                $this->tagStart = $commentTags[$tagPos] ?? $commentTags[0];

                $attrs = trim($matches[2][$tagPos], '()');

                if ($tagName === 'ORM\JoinTable') {
                    // Skip because nested annotations are not supported at the moment
                    continue;
                }

                $this->tags[1][$this->tagStart] = $tagName;
                $this->tags[2][$this->tagStart] = $this->parseAnnotationAttributes($attrs);
            }
        }
    }

    protected function parsePropertyAttributes(File $phpcsFile, int $propertyPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $attributeStart = $propertyPtr;

        while (($attributeEnd = $phpcsFile->findPrevious(T_ATTRIBUTE_END, $attributeStart - 1)) !== false) {
            $attributeFor = $phpcsFile->findNext([T_VARIABLE, T_CLASS, T_INTERFACE, T_FUNCTION], $attributeEnd + 1);

            if ($attributeFor !== $propertyPtr) {
                break;
            }

            $attributeStart = $tokens[$attributeEnd]['attribute_opener'];
            $parenthesisOpener = $phpcsFile->findNext(T_OPEN_PARENTHESIS, $attributeStart + 1, $attributeEnd);
            $attributeName = trim($phpcsFile->getTokensAsString($attributeStart + 1, ($parenthesisOpener ?: $attributeEnd) - $attributeStart - 1));
            $attributeParams = [];

            if (strpos($attributeName, 'ORM') !== 0 || $attributeName === 'ORM\JoinTable') {
                continue;
            }

            if ($parenthesisOpener !== false) {
                $parenthesisCloser = $tokens[$parenthesisOpener]['parenthesis_closer'];
                $attributeParamsString = $phpcsFile->getTokensAsString($parenthesisOpener + 1, $parenthesisCloser - $parenthesisOpener - 1);

                $this->tagStart = $attributeStart;
                $attributeParams = $this->parseNativeAttributeParams($attributeParamsString);
            }

            $this->tags[1][$attributeStart] = $attributeName;
            $this->tags[2][$attributeStart] = $attributeParams;
        }
    }

    /**
     * Parses annotation attributes and returns an array.
     *
     * @param string $text
     *
     * @return array
     */
    protected function parseAnnotationAttributes(string $text): array
    {
        $attributes = [];

        $text = trim($text);

        while ($text !== '') {
            if (strpos($text, '{') === 0) {
                if ($endPos = strpos($text, '}')) {
                    $text = substr($text, $endPos + 1);

                    continue;
                }
            }

            $eqPos = strpos($text, '=');
            $name = substr($text, 0, $eqPos);
            if (strpos($name, ' ') !== false) {
                $name = trim($name);
                $error = 'Found extra space before attribute "%s" name';
                $data = [$name];
                $this->reportError($error, $this->tagStart, 'ExtraSpace', $data);
            }

            $valuePos = $eqPos + 1;
            if (substr($text, $valuePos, 1) === ' ') {
                $error = 'Found extra space before attribute "%s" value';
                $data = [$name];
                $this->reportError($error, $this->tagStart, 'ExtraSpace', $data);
                do {
                    $valuePos++;
                } while (substr($text, $valuePos, 1) === ' ');
            }
            if (substr($text, $valuePos, 1) === '"') {
                $valueEndPos = strpos($text, '"', $valuePos + 1);
                if ($valueEndPos === false) {
                    $error = 'Unexpected end of string';
                    $this->reportError($error, $this->tagStart, 'UnexpectedEnd');
                    $valueEndPos = strlen($text) - 1;
                }
                $value = substr($text, $valuePos + 1, $valueEndPos - $valuePos - 1);
            } elseif (substr($text, $valuePos, 1) === '{') {
                $valueEndPos = strpos($text, '}', $valuePos + 1);
                if ($valueEndPos === false) {
                    $error = 'Unexpected end of array';
                    $this->reportError($error, $this->tagStart, 'UnexpectedEnd');
                    $valueEndPos = strlen($text) - 1;
                }
                $value = substr($text, $valuePos, $valueEndPos - $valuePos + 1);
            } else {
                $commaPos = strpos($text, ',', $valuePos);
                if ($commaPos !== false) {
                    $valueEndPos = $commaPos - 1;
                } else {
                    $valueEndPos = strlen($text) - 1;
                }
                $value = substr($text, $valuePos, $valueEndPos - $valuePos + 1);

                if (in_array(strtolower($value), ['false', 'true'])) {
                    $value = strtolower($value) === 'true';
                } elseif (is_numeric($value)) {
                    $value = (int) $value;
                }
            }

            $attributes[$name] = $value;

            if ($valueEndPos < strlen($text) - 1) {
                $delimiterPos = $valueEndPos + 1;
                $delimiter = substr($text, $delimiterPos, 1);
                if ($delimiter === ' ') {
                    $error = 'Extra space after attribute "%s" value';
                    $data = [$name];
                    $this->reportError($error, $this->tagStart, 'ExtraSpace', $data);
                    do {
                        $delimiterPos++;
                        $delimiter = substr($text, $delimiterPos, 1);
                    } while ($delimiter === ' ');
                }
                if ($delimiter === ',') {
                    if (substr($text, $delimiterPos + 1, 1) === ' ') {
                        $delimiterPos++;
                    }
                }
                $valueEndPos = $delimiterPos;
            }

            $text = substr($text, $valueEndPos + 1);
        }

        return $attributes;
    }

    /**
     * Parses native attribute params and returns an array.
     *
     * @param string $text
     *
     * @return array
     */
    protected function parseNativeAttributeParams(string $text): array
    {
        $params = [];

        $text = trim($text);

        while ($text !== '') {
            if (strpos($text, '[') === 0) {
                if ($endPos = strpos($text, ']')) {
                    $text = substr($text, $endPos + 1);

                    continue;
                }
            }

            $colonPos = strpos($text, ':');
            $name = substr($text, 0, $colonPos);

            if (strpos($name, ' ') !== false && strpos($name, "\n") === false) {
                $name = trim($name);
                $error = 'Found extra space before attribute "%s" name';
                $data = [$name];
                $this->reportError($error, $this->tagStart, 'ExtraSpace', $data);
            }

            $name = trim($name, " \r\n");
            $valuePos = $colonPos + 1;

            if (substr($text, $valuePos, 1) !== ' ') {
                $error = 'Need space before attribute "%s" value';
                $data = [$name];
                $this->reportError($error, $this->tagStart, 'NeedSpace', $data);
            }

            while (substr($text, $valuePos, 1) === ' ') {
                $valuePos++;
            }

            if (substr($text, $valuePos, 1) === "'") {
                $valueEndPos = strpos($text, "'", $valuePos + 1);

                if ($valueEndPos === false) {
                    $error = 'Unexpected end of string';
                    $this->reportError($error, $this->tagStart, 'UnexpectedEnd');
                    $valueEndPos = strlen($text) - 1;
                }

                $value = substr($text, $valuePos + 1, $valueEndPos - $valuePos - 1);
            } elseif (substr($text, $valuePos, 1) === '[') {
                $valueEndPos = strpos($text, ']', $valuePos + 1);

                if ($valueEndPos === false) {
                    $error = 'Unexpected end of array';
                    $this->reportError($error, $this->tagStart, 'UnexpectedEnd');
                    $valueEndPos = strlen($text) - 1;
                }

                $value = substr($text, $valuePos, $valueEndPos - $valuePos + 1);
            } else {
                $commaPos = strpos($text, ',', $valuePos);

                if ($commaPos !== false) {
                    $valueEndPos = $commaPos - 1;
                } else {
                    $valueEndPos = strlen($text) - 1;
                }

                $value = substr($text, $valuePos, $valueEndPos - $valuePos + 1);

                if (in_array(strtolower($value), ['false', 'true'])) {
                    $value = strtolower($value) === 'true';
                } elseif (is_numeric($value)) {
                    $value = (int) $value;
                }
            }

            $params[$name] = $value;

            if ($valueEndPos < strlen($text) - 1) {
                $delimiterPos = $valueEndPos + 1;
                $delimiter = substr($text, $delimiterPos, 1);

                if ($delimiter === ' ') {
                    $error = 'Extra space after attribute "%s" value';
                    $data = [$name];
                    $this->reportError($error, $this->tagStart, 'ExtraSpace', $data);

                    do {
                        $delimiterPos++;
                        $delimiter = substr($text, $delimiterPos, 1);
                    } while ($delimiter === ' ');
                }

                if ($delimiter === ',') {
                    if (substr($text, $delimiterPos + 1, 1) === ' ') {
                        $delimiterPos++;
                    }
                }

                $valueEndPos = $delimiterPos;
            }

            $text = substr($text, $valueEndPos + 1);
        }

        return $params;
    }

    /**
     * Processes ORM annotation.
     *
     * @param string $name
     * @param array  $attributes
     */
    protected function processORMAnnotation(string $name, array $attributes): void
    {
        $name = preg_replace('/^ORM\\\\/', '', $name);

        if ($this->memberType !== null) {
            $attributes = $this->inferTypedPropertyAnnotationDefaults($name, $attributes);
        }

        $this->validateAnnotationDeclaration($name, $attributes);

        $tokens = $this->phpcsFile->getTokens();
        $this->varName = ltrim($tokens[$this->memberPtr]['content'], '$');

        switch ($name) {
            case 'Column':
                $this->validateAnnotationColumn($name, $attributes);
                break;

            case 'JoinColumn':
                $this->validateAnnotationJoinColumn($name, $attributes);
                break;

            case 'ManyToOne':
            case 'OneToOne':
                $this->validateAnnotationRelationToOne($name, $attributes);
                break;

            case 'ManyToMany':
            case 'OneToMany':
                $this->validateAnnotationRelationToMany($name, $attributes);
                break;
        }
    }

    /**
     * @param string $name
     * @param array  $attributes
     *
     * @return array
     *
     * @see https://www.doctrine-project.org/2021/05/24/orm2.9.html
     */
    protected function inferTypedPropertyAnnotationDefaults(string $name, array $attributes): array
    {
        $type = ltrim($this->memberType, '?\\');

        switch ($name) {
            case 'Column':
                $attributes = $this->inferTypedPropertyColumnAnnotationDefaults($attributes, $type);
                break;

            case 'ManyToOne':
            case 'OneToOne':
                $attributes = $this->inferTypedPropertyRelationToOneAnnotationDefaults($attributes, $type);
                break;
        }

        return $attributes;
    }

    /**
     * @param array  $attributes
     * @param string $type
     *
     * @return array
     *
     * @see \Doctrine\ORM\Mapping\ClassMetadataInfo::validateAndCompleteTypedFieldMapping()
     */
    protected function inferTypedPropertyColumnAnnotationDefaults(array $attributes, string $type): array
    {
        if (!isset($attributes['type'])) {
            switch ($type) {
                case 'DateInterval':
                    $attributes['type'] = 'dateinterval';
                    break;

                case 'DateTime':
                    $attributes['type'] = 'datetime';
                    break;

                case 'DateTimeImmutable':
                    $attributes['type'] = 'datetime_immutable';
                    break;

                case 'array':
                    $attributes['type'] = 'json';
                    break;

                case 'bool':
                    $attributes['type'] = 'boolean';
                    break;

                case 'float':
                    $attributes['type'] = 'float';
                    break;

                case 'int':
                    $attributes['type'] = 'integer';
                    break;

                case 'string':
                    $attributes['type'] = 'string';
                    break;

                default:
                    if ($this->soundsLikeClass($type)) {
                        $attributes['type'] = 'string';
                        $attributes['enumType'] = $type . self::CLASS_SUFFIX;
                    }
            }
        }

        return $attributes;
    }

    /**
     * @param array  $attributes
     * @param string $type
     *
     * @return array
     *
     * @see \Doctrine\ORM\Mapping\ClassMetadataInfo::validateAndCompleteTypedAssociationMapping()
     */
    protected function inferTypedPropertyRelationToOneAnnotationDefaults(array $attributes, string $type): array
    {
        if (!isset($attributes['targetEntity'])) {
            $attributes['targetEntity'] = $type . self::CLASS_SUFFIX;
        }

        return $attributes;
    }

    /**
     * Validates annotation declaration.
     *
     * @param string $name
     * @param array  $attributes
     */
    protected function validateAnnotationDeclaration(string $name, array $attributes): void
    {
        $referenceName = preg_replace('/^ORM\\\\/', '', $name);

        if (!isset($this->reference[$referenceName])) {
            return;
        }

        $rules = $this->reference[$referenceName];

        if (isset($rules['requires'])) {
            $this->validateAnnotationRequirements($name, $rules['requires']);
        }

        if (isset($rules['attributes'])) {
            $requiredAttributes = $rules['required'] ?? [];

            $this->validateAnnotationAttributes($name, $attributes, $rules['attributes'], $requiredAttributes);
        }
    }

    /**
     * Validates annotation requirements.
     *
     * @param string $name
     * @param array  $requires
     */
    protected function validateAnnotationRequirements(string $name, array $requires): void
    {
        foreach ($requires as $requiresName) {
            if (is_array($requiresName)) {
                $found = false;
                foreach ($requiresName as $requiresEitherName) {
                    if ($found = $this->hasAnnotation($requiresEitherName)) {
                        break;
                    }
                }
            } else {
                $found = $this->hasAnnotation($requiresName);
            }

            if (!$found) {
                $error = 'Annotation %s requires %s which is not found';
                $data = [$name, is_array($requiresName) ? implode(' or ', $requiresName) : $requiresName];
                $this->reportError($error, $this->tagStart, 'AnnotationRequired', $data);
            }
        }
    }

    /**
     * Validates annotation attributes.
     *
     * @param string $name
     * @param array  $attributes
     * @param array  $attributesRules
     * @param array  $requiredAttributes
     */
    protected function validateAnnotationAttributes(
        string $name,
        array $attributes,
        array $attributesRules,
        array $requiredAttributes
    ): void
    {
        if (!empty($requiredAttributes)) {
            $missingAttrs = array_keys(array_diff_key(array_flip($requiredAttributes), $attributes));
            if (count($missingAttrs) > 0) {
                $error = 'Annotation %s must have the following attributes: %s';
                $data = [$name, implode(', ', $missingAttrs)];
                $this->reportError($error, $this->tagStart, 'AttributeRequired', $data);
            }
        }

        $unknownAttrs = array_keys(array_diff_key($attributes, $attributesRules));
        if (count($unknownAttrs) > 0) {
            $error = 'Annotation %s has unknown attributes: %s';
            $data = [$name, implode(', ', $unknownAttrs)];
            $this->reportError($error, $this->tagStart, 'AttributeUnknown', $data);
        }

        foreach ($attributesRules as $attrName => $attrType) {
            if (isset($attributes[$attrName])) {
                $this->validateAnnotationAttributeType($name, $attrName, $attrType, $attributes[$attrName]);
            }
        }
    }

    /**
     * Validates annotation attribute type.
     *
     * @param string $name
     * @param string $attrName
     * @param mixed  $attrType
     * @param mixed  $value
     */
    protected function validateAnnotationAttributeType(string $name, string $attrName, $attrType, $value): void
    {
        $valid = true;

        if (is_array($attrType)) {
            $valid = in_array($value, $attrType, true);
            $validType = sprintf('["%s"]', implode('", "', $attrType));
        } else {
            $validType = $attrType;

            switch ($attrType) {
                case 'string':
                    $valid = is_string($value) && !empty($value);
                    break;

                case 'integer':
                case 'tinyint':
                case 'smallint':
                case 'bigint':
                    $valid = is_int($value);
                    break;

                case 'boolean':
                    $valid = is_bool($value);
                    break;

                case 'array':
                    $valid = in_array(substr($value, 0, 1), ['{', '['], true);
                    break;

                case 'class':
                    $valid = ($this->soundsLikeClass($value) || $value === 'self::class') && (
                            (strpos($value, "\\") !== false)
                            || strpos($value, self::CLASS_SUFFIX, -self::CLASS_SUFFIX_LENGTH)
                        );
                    break;

                default:
                    $error = 'Annotation %s has attribute %s with unknown type %s';
                    $data = [$name, $attrName, $validType];
                    $this->reportError($error, $this->tagStart, 'AttributeUnknownType', $data);
            }
        }

        if (!$valid) {
            $error = 'Annotation %s has attribute %s with invalid type; expected %s';
            $data = [$name, $attrName, $validType];
            $this->reportError($error, $this->tagStart, 'AttributeInvalidType', $data);
        }
    }

    protected function hasAnnotation(string $name): bool
    {
        return in_array('ORM\\' . $name, $this->tags[1], true);
    }

    /**
     * Validate annotation Column.
     *
     * @param string $name
     * @param array  $attributes
     */
    protected function validateAnnotationColumn(string $name, array $attributes): void
    {
        if (isset($attributes['name'])) {
            $columnName = trim($attributes['name'], '`');
            $expectedColumnName = $this->underScore($this->varName);
            $camelCasedColumnName = $this->camelCase($columnName);
            if (($columnName !== $expectedColumnName) && ($this->varName !== $camelCasedColumnName)) {
                $error = 'Column name must be underscored variable name; expected "%s" but found "%s"';
                $data = [$expectedColumnName, $columnName];
                $this->reportError($error, $this->tagStart, 'ColumnUnderscored', $data);
            }
        }

        $expectedType = $this->memberType;

        if (isset($attributes['type'])) {
            $columnType = $attributes['type'];

            if (isset($attributes['enumType'])) {
                $columnType = $this->getShortClassName($attributes['enumType']);
            }

            if ($expectedType = $this->suggestType($columnType)) {
                if (!$this->varType && !$this->memberType) {
                    $error = 'Variable type required for column; expected "%s"';
                    $data = [$expectedType];
                    $this->reportError($error, $this->tagStart, 'VariableTypeRequired', $data);
                }

                if ($this->varType && !$this->isSameTypes($expectedType, $this->varType)) {
                    $error = 'Variable type must match column type; expected "%s" but found "%s"';
                    $data = [$expectedType, $this->varType];
                    $this->reportError($error, $this->tagStart, 'VariableTypeMismatch', $data);
                }

                if ($this->memberType && !$this->isSameTypes($expectedType, trim($this->memberType, '?'))) {
                    $error = 'Property type must match column type; expected "%s" but found "%s"';
                    $data = [$expectedType, $this->memberType];
                    $this->reportError($error, $this->tagStart, 'PropertyTypeMismatch', $data);
                }
            }

            if ($columnType === 'varchar' && !isset($attributes['length'])) {
                $error = 'Column of type varchar must have specified length';
                $this->reportError($error, $this->tagStart, 'ColumnAttributeRequired');
            }

            if ($columnType === 'decimal' && !(isset($attributes['precision'], $attributes['scale']))) {
                $error = 'Column of type decimal must have specified precision and length';
                $this->reportError($error, $this->tagStart, 'ColumnAttributeRequired');
            }
        }

        $columnNullable = $attributes['nullable'] ?? false;

        $this->validateMethodDeclaration(
            $name,
            'getter',
            'get' . ucfirst($this->varName),
            true,
            $expectedType
        );

        $this->validateMethodDeclaration(
            $name,
            'setter',
            'set' . ucfirst($this->varName),
            !$this->hasAnnotation('GeneratedValue'),
            '$this',
            $this->varName,
            $expectedType,
            $columnNullable
        );
    }

    protected function camelCase(string $value): string
    {
        return lcfirst(str_replace(' ', '', ucwords(trim(str_replace('_', ' ', $value)))));
    }

    protected function underScore(string $value): string
    {
        return strtolower(preg_replace_callback('/([a-z0-9])([A-Z])/', [$this, 'underScoreCallback'], $value));
    }

    /**
     * Callback for underScore method.
     *
     * @param array $matches
     *
     * @return string
     */
    private function underScoreCallback(array $matches): string
    {
        return $matches[1] . '_' . strtolower($matches[2]);
    }

    protected function suggestType(string $type): string
    {
        // support Doctrine\DBAL\Types\Types constants
        if (strpos($type, 'Types::') === 0) {
            $type = strtolower(substr($type, 7));

            if (substr($type, -8, 8) === '_mutable') {
                $type = substr($type, 0, -8);
            }
        }

        if (isset($this->mappingTypes[$type])) {
            return $this->mappingTypes[$type];
        }

        if ($this->soundsLikeClass($type)) {
            return $type;
        }

        return $this->calculateMappingTypeDynamically($type);
    }

    protected function calculateMappingTypeDynamically(string $columnType, string $fallbackToType = 'string'): string
    {
        $calculatedType = $fallbackToType;
        if ($this->useDynamicalCalculationForEnumColumnType) {
            if (strpos($columnType, '_enum_') !== false) {
                $columnTypeParts = explode('_enum_', $columnType);
                $calculatedType = ucfirst($this->camelCase(implode(array_map('ucfirst', $columnTypeParts))));
            }
        }

        return $calculatedType;
    }

    /**
     * Returns a list of class methods.
     *
     * @return array
     */
    protected function getMethods(): array
    {
        $this->validateCache();

        if ($this->methods === null) {
            $this->methods = [];
            $tokens = $this->phpcsFile->getTokens();
            $funcPtr = $this->memberPtr;

            while (($funcPtr = $this->phpcsFile->findNext(T_FUNCTION, $funcPtr + 1)) !== false) {
                if (($funcNamePtr = $this->phpcsFile->findNext([T_WHITESPACE], $funcPtr + 1, null, true)) !== false) {
                    $this->methods[$funcPtr] = $tokens[$funcNamePtr]['content'];
                }
            }
        }

        return $this->methods;
    }

    protected function getDocComment(int $stackPtr): ?string
    {
        $commentEnd = $this->phpcsFile->findPrevious(T_DOC_COMMENT_CLOSE_TAG, $stackPtr - 1);
        if ($commentEnd === false) {
            return null;
        }

        $commentFor = $this->phpcsFile->findNext([T_VARIABLE, T_CLASS, T_INTERFACE, T_FUNCTION], $commentEnd + 1);
        if ($commentFor !== $stackPtr) {
            return null;
        }

        $commentStart = $this->phpcsFile->findPrevious(T_DOC_COMMENT_OPEN_TAG, $commentEnd - 1) + 1;

        return $this->phpcsFile->getTokensAsString($commentStart, ($commentEnd - $commentStart + 1));
    }

    protected function getMethodClassName(int $stackPtr): ?string
    {
        $this->validateCache();

        if ($this->className === null) {
            $this->className = '';
            $tokens = $this->phpcsFile->getTokens();

            if (isset($tokens[$stackPtr]['conditions']) && count($conditions = $tokens[$stackPtr]['conditions']) > 0) {
                reset($conditions);
                $classPtr = key($conditions);

                if (in_array($tokens[$classPtr]['code'], [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
                    $classNamePtr = $this->phpcsFile->findNext([T_WHITESPACE], $classPtr + 1, null, true);
                    if ($classNamePtr !== false) {
                        $this->className = $tokens[$classNamePtr]['content'];
                    }
                }
            }
        }

        return $this->className;
    }

    protected function validateMethodDeclaration(
        string $ownerType,
        string $methodType,
        string $methodName,
        bool $methodRequired,
        string $returnType = null,
        string $argumentName = null,
        string $argumentType = null,
        bool $argumentNullable = false
    ): void
    {
        $tokens = $this->phpcsFile->getTokens();
        $methods = $this->getMethods();
        $methodPtr = array_search($methodName, $methods, true);
        $errorPrefix = sprintf('%s%s', ucfirst($ownerType), ucfirst($methodType));
        $ownerType = ucfirst($ownerType);

        if ($methodPtr === false && strpos($methodName, 'get') === 0) {
            $shortMethodName = lcfirst(substr($methodName, 3));
            if (strpos($shortMethodName, 'is') !== 0) {
                $shortMethodName = 'is' . ucfirst($shortMethodName);
            }
            $methodPtr = array_search($shortMethodName, $methods);
            if ($methodPtr !== false) {
                $methodName = $shortMethodName;
            }
        }

        if ($methodPtr === false) {
            if ($methodRequired) {
                $error = '%s must have %s method named "%s"';
                $data = [$ownerType, $methodType, $methodName];
                $this->reportError($error, $this->tagStart, $errorPrefix . 'Required', $data);
            }
        } else {
            if ($returnType !== null) {
                if ($returnType === '$this') {
                    $returnType = sprintf('%s|self|static', $this->getShortClassName($this->getMethodClassName($methodPtr)));
                }

                $methodProperties = $this->phpcsFile->getMethodProperties($methodPtr);
                $methodReturnType = ltrim($methodProperties['return_type'], '?');

                $scopeOpenPtr = $tokens[$methodPtr]['scope_opener'];
                $scopeClosePtr = $tokens[$methodPtr]['scope_closer'];
                $returnPtr = $this->phpcsFile->findPrevious(T_RETURN, $scopeClosePtr, $scopeOpenPtr);

                if ($returnPtr === false && !$methodReturnType) {
                    $error = '%s %s "%s" must have return statement which returns %s';
                    $data = [$ownerType, $methodType, $methodName, $returnType];
                    $this->reportError($error, $methodPtr, $errorPrefix . 'ReturnRequired', $data);
                }

                if ($comment = $this->getDocComment($methodPtr)) {
                    if (
                        !preg_match('/@return\s+([^\s<]+(<.*>)?)/', $comment, $matches)
                        || !$this->isSameTypes($returnType, $matches[1])
                    ) {
                        $error = '%s %s "%s" must have return type "%s" in doc-comment';
                        $data = [$ownerType, $methodType, $methodName, $returnType];
                        $this->reportError($error, $methodPtr, $errorPrefix . 'ReturnType', $data);
                    }
                }

                if ($methodReturnType && !$this->isSameTypes($returnType, $methodReturnType)) {
                    $error = '%s %s "%s" must have return type "%s"';
                    $data = [$ownerType, $methodType, $methodName, $returnType];
                    $this->reportError($error, $methodPtr, $errorPrefix . 'ReturnType', $data);
                }
            }

            if ($argumentName !== null) {
                $argsOpenPtr = $tokens[$methodPtr]['parenthesis_opener'];
                $argsClosePtr = $tokens[$methodPtr]['parenthesis_closer'];
                $argPtr = $this->phpcsFile->findNext([T_NS_SEPARATOR, T_NULLABLE, T_STRING, T_VARIABLE], $argsOpenPtr, $argsClosePtr);
                if ($argPtr === false) {
                    $error = '%s %s "%s" must have at least one argument';
                    $data = [$ownerType, $methodType, $methodName];
                    $this->reportError($error, $methodPtr, $errorPrefix . 'ArgumentRequired', $data);
                } else {
                    $argType = null;
                    if (in_array($tokens[$argPtr]['code'], [T_NULLABLE, T_STRING, T_NS_SEPARATOR], true)) {
                        $argTypeStartPtr = $argPtr;
                        $argTypeEndPtr = $this->phpcsFile->findNext([T_NULLABLE, T_STRING, T_NS_SEPARATOR], $argPtr, $argsClosePtr, true);
                        if ($argTypeEndPtr !== false) {
                            $argType = $this->phpcsFile->getTokensAsString($argTypeStartPtr, $argTypeEndPtr - $argTypeStartPtr);
                            $argPtr = $this->phpcsFile->findNext(T_VARIABLE, $argTypeEndPtr + 1, $argsClosePtr);
                        }
                    }

                    if ($tokens[$argPtr]['code'] === T_VARIABLE) {
                        $argName = ltrim($tokens[$argPtr]['content'], '$');
                        if ($argName !== $argumentName) {
                            $error = '%s %s "%s" argument must have name "%s"';
                            $data = [$ownerType, $methodType, $methodName, $argumentName];
                            $this->reportError($error, $methodPtr, $errorPrefix . 'ArgumentName', $data);
                        }
                    }

                    if ($argType !== null) {
                        $argNullable = false;
                        if (strpos($argType, '?') === 0) {
                            $argNullable = true;
                            $argType = substr($argType, 1);
                        }

                        if (
                            !$this->isSameTypes($argumentType, $argType)
                            && !$this->isTypeHintMappedToConcreteType($argumentType, $argType)
                        ) {
                            $error = '%s %s "%s" argument must have typehint "%s", instead of typehint "%s"';
                            $data = [$ownerType, $methodType, $methodName, $argumentType, $argType];
                            $this->reportError($error, $methodPtr, $errorPrefix . 'ArgumentType', $data);
                        }

                        $nullPtr = $this->phpcsFile->findNext([T_NULL, T_COMMA], $argPtr, $argsClosePtr);
                        $isNullable = ($nullPtr && $tokens[$nullPtr]['code'] === T_NULL) || $argNullable;
                        if ($isNullable === false && $argumentNullable) {
                            $error = '%s %s "%s" argument must be nullable';
                            $data = [$ownerType, $methodType, $methodName];
                            $this->reportError($error, $methodPtr, $errorPrefix . 'ArgumentNullable', $data);
                        } elseif ($isNullable !== false && !$argumentNullable) {
                            $error = '%s %s "%s" argument must be not-nullable';
                            $data = [$ownerType, $methodType, $methodName];
                            $this->reportError($error, $methodPtr, $errorPrefix . 'ArgumentNotNullable', $data);
                        }
                    }

                    if ($comment = $this->getDocComment($methodPtr)) {
                        if (
                            !preg_match('/@param\s+(\S+)/', $comment, $matches)
                            || (
                                !$this->isSameTypes($argumentType, $matches[1])
                                && !$this->isTypeHintMappedToConcreteType($argumentType, $argType)
                            )
                        ) {
                            $error = '%s %s "%s" must have param "%s" with type "%s" in doc-comment';
                            $data = [$ownerType, $methodType, $methodName, $argumentName, $argumentType];
                            $this->reportError($error, $methodPtr, $errorPrefix . 'ArgumentDocType', $data);
                        }
                    }
                }
            }
        }
    }

    protected function validateCache(): void
    {
        $file = $this->phpcsFile->getFilename();

        if ($this->currFile !== $file) {
            $this->currFile = $file;

            $this->methods = null;
            $this->className = null;
            $this->initializedMembers = null;
        }
    }

    /**
     * Returns a list of members initialized in constructor.
     *
     * @param boolean $constructorRequired
     *
     * @return array
     */
    protected function getInitializedMembers(bool $constructorRequired = true): array
    {
        $this->validateCache();

        if ($this->initializedMembers === null) {
            $this->initializedMembers = [];

            $tokens = $this->phpcsFile->getTokens();
            $methods = $this->getMethods();
            $methodPtr = array_search('__construct', $methods, true);

            if ($methodPtr === false) {
                if ($constructorRequired) {
                    $error = 'Class should have constructor with properties initialization.';
                    $this->reportError($error, $this->memberPtr, 'ConstructorRequired');
                }
            } else {
                $scopeOpenPtr = $tokens[$methodPtr]['scope_opener'];
                $scopeClosePtr = $tokens[$methodPtr]['scope_closer'];
                $thisPtr = $scopeOpenPtr;

                while ($thisPtr = $this->phpcsFile->findNext(T_VARIABLE, $thisPtr + 1, $scopeClosePtr, false, '$this')) {
                    $endPtr = $this->phpcsFile->findNext(T_SEMICOLON, $thisPtr + 1);

                    if ($arrowPtr = $this->phpcsFile->findNext(T_OBJECT_OPERATOR, $thisPtr + 1, $endPtr)) {
                        if ($varPtr = $this->phpcsFile->findNext(T_STRING, $arrowPtr + 1, $endPtr)) {
                            if ($equalPtr = $this->phpcsFile->findNext(T_EQUAL, $varPtr + 1, $endPtr)) {
                                if ($valPtr = $this->phpcsFile->findNext(T_WHITESPACE, $equalPtr + 1, $endPtr, true)) {
                                    if ($endPtr = $this->phpcsFile->findNext(T_SEMICOLON, $valPtr + 1)) {
                                        $this->initializedMembers[$tokens[$varPtr]['content']] =
                                            $this->phpcsFile->getTokensAsString($valPtr, $endPtr - $valPtr);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return $this->initializedMembers;
    }

    protected function getShortClassName(string $className): string
    {
        if (strpos($className, "\\") !== false) {
            $className = ltrim(strrchr($className, "\\"), "\\");
        }

        if (
            strlen($className) > self::CLASS_SUFFIX_LENGTH
            && strpos($className, self::CLASS_SUFFIX, -self::CLASS_SUFFIX_LENGTH)
        ) {
            $className = substr($className, 0, -self::CLASS_SUFFIX_LENGTH);
        }

        if ($className === 'self') {
            $className = $this->getMethodClassName($this->memberPtr);
        }

        return $className;
    }

    /**
     * Returns annotation attributes.
     *
     * @param string $name
     *
     * @return array
     */
    protected function getAnnotationAttributes(string $name): array
    {
        $key = array_search('ORM\\' . $name, $this->tags[1], true);

        return $key !== false ? $this->tags[2][$key] : [];
    }

    /**
     * Validates annotation relations ManyToOne and OneToOne.
     *
     * @param string $name
     * @param array  $attributes
     */
    protected function validateAnnotationRelationToOne(string $name, array $attributes): void
    {
        $type = $this->getShortClassName($attributes['targetEntity']);

        $joinAttributes = $this->getAnnotationAttributes('JoinColumn');
        $nullable = (bool) ($joinAttributes['nullable'] ?? true);

        $this->validateMethodDeclaration($name, 'getter', 'get' . ucfirst($this->varName), true, $type);

        $this->validateMethodDeclaration($name, 'setter', 'set' . ucfirst($this->varName), true, '$this', $this->varName, $type, $nullable);
    }

    /**
     * Validates annotation relations ManyToMany and OneToMany.
     *
     * @param string $name
     * @param array  $attributes
     */
    protected function validateAnnotationRelationToMany(string $name, array $attributes): void
    {
        $varName = $this->varName;

        if (substr($varName, -1, 1) !== 's') {
            $error = 'Variable "%s" name "%s" must be plural';
            $data = [$name, $varName];
            $this->reportError($error, $this->memberPtr, $name . 'VariablePlural', $data);
        } else {
            $varName = substr($varName, 0, -1);
            if (substr($varName, -2, 2) === 'ie') {
                $varName = substr($varName, 0, -2) . 'y';
            } elseif (substr($varName, -2, 2) === 'se') {
                $varName = substr($varName, 0, -1);
            }
        }

        $members = $this->getInitializedMembers();
        if (!isset($members[$this->varName])) {
            $error = 'Variable "%s" must be initialized in the class constructor';
            $data = [$this->varName];
            $this->reportError($error, $this->memberPtr, $name . 'VariableNotInitialized', $data);
        } elseif (strtolower(trim($members[$this->varName])) !== 'new arraycollection()') {
            $error = 'Variable "%s" must be initialized in the class constructor as ArrayCollection; found "%s"';
            $data = [$this->varName, $members[$this->varName]];
            $this->reportError($error, $this->memberPtr, $name . 'VariableCollection', $data);
        }

        $type = $this->getShortClassName($attributes['targetEntity']);

        $this->validateMethodDeclaration($name, 'adder', 'add' . ucfirst($varName), true, '$this', $varName, $type);

        $this->validateMethodDeclaration($name, 'remover', 'remove' . ucfirst($varName), true, null, $varName, $type);

        $this->validateMethodDeclaration(
            $name,
            'getter',
            'get' . ucfirst($this->varName),
            true,
            sprintf('%s[]|Collection|ArrayCollection|Collection<%s>|Collection<int, %s>', $type, $type, $type)
        );
    }

    /**
     * Validates annotation JoinColumn.
     *
     * @param string $name
     * @param array  $attributes
     */
    protected function validateAnnotationJoinColumn(string $name, array $attributes): void
    {
        if (isset($attributes['name'])) {
            $columnName = $attributes['name'];
            $expectedColumnName = $this->underScore($this->varName) . '_id';

            if ($columnName !== $expectedColumnName) {
                $error = '%s name must be underscored variable name; expected "%s" but found "%s"';
                $data = [$name, $expectedColumnName, $columnName];
                $this->reportError($error, $this->tagStart, 'JoinColumnNameFormat', $data);
            }
        }
    }

    /**
     * Reports error.
     *
     * @param string  $error
     * @param integer $stackPtr
     * @param string  $code
     * @param array   $data
     * @param integer $severity
     * @param boolean $fixable
     */
    protected function reportError(
        string $error,
        int $stackPtr,
        string $code,
        array $data = [],
        int $severity = 0,
        bool $fixable = false
    ): void
    {
        if (!in_array($code, $this->codingStandardsIgnoreErrors, true)) {
            $this->phpcsFile->addError($error, $stackPtr, $code, $data, $severity, $fixable);
        }
    }

    protected function parseCodingStandardsIgnoreErrors(string $comment): void
    {
        $this->codingStandardsIgnoreErrors = [];

        if (!preg_match_all('/@codingStandardsIgnoreError\s+([a-zA-Z0-9_\.]+)/', $comment, $matches, PREG_PATTERN_ORDER)) {
            return;
        }

        $sniffCode = Common::getSniffCode(get_class($this));

        foreach ($matches[1] as $ignoreCode) {
            if (strpos($ignoreCode, $sniffCode) === 0) {
                $ignoreCode = substr($ignoreCode, strlen($sniffCode) + 1);
            }

            $this->codingStandardsIgnoreErrors[] = $ignoreCode;
        }
    }

    protected function isTypeHintMappedToConcreteType(string $expectedType, ?string $realTypeHint): bool
    {
        return ($this->concreteTypeToBaseTypeMap[$expectedType] ?? null) === $realTypeHint;
    }

    private function isSameTypes(string $expectedType, string $actualType): bool
    {
        $expectedType = ltrim($expectedType, "\\");
        $actualType = ltrim($actualType, "\\");

        if (
            $expectedType === $actualType
            || ($expectedType === 'array' && preg_match('/\[\]($|\|)/', $actualType))
        ) {
            return true;
        }

        if ($expectedType === 'DateTime') {
            $expectedType .= '|DateTimeInterface|DateTimeImmutable';
        }

        $expectedTypes = array_map([$this, 'getLongType'], explode('|', $expectedType));
        $actualTypes = array_map([$this, 'getLongType'], explode('|', $actualType));

        return count(array_intersect($actualTypes, $expectedTypes)) > 0;
    }

    private function getLongType(string $shortType): string
    {
        return self::SCALAR_TYPES[$shortType] ?? $shortType;
    }

    private function soundsLikeClass(string $type): bool
    {
        return ucfirst($type) === $type;
    }
}
