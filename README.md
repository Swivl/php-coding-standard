Swivl Coding Standard for PHP_CodeSniffer
=========================================

This coding standard is much more strict version of [PSR-12](https://www.php-fig.org/psr/psr-12/). 

Installation
------------

Install coding standard using composer:
```sh
composer require --dev swivl/php-coding-standard
```

Configuration
-------------

Create file `phpcs.xml.dist` in the root of your project with the content similar to:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<ruleset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/squizlabs/php_codesniffer/phpcs.xsd">

    <arg name="basepath" value="."/>
    <arg name="cache" value=".phpcs-cache"/>
    <arg name="colors"/>
    <arg value="p"/>
    <arg name="extensions" value="php"/>
    <arg name="tab-width" value="4"/>
    <arg name="report-width" value="120"/>

    <rule ref="vendor/swivl/php-coding-standard/Swivl/ruleset.xml"/>

    <rule ref="Generic.Files.LineLength.TooLong">
        <exclude-pattern>*/src/Migrations/*</exclude-pattern>
    </rule>

    <file>src/</file>
    <file>tests/</file>

    <exclude-pattern>*/Resources/*</exclude-pattern>

</ruleset>
```

Configure your IDE to use `phpcs.xml.dist` as the default ruleset for PHP_CodeSniffer.

Checking code style in your project
-----------------------------------

Check the whole project:
```sh
bin/phpcs
```

Check a single file or directory:
```sh
bin/phpcs path/to/file/or/directory
```

Automatically fix errors:
```sh
bin/phpcbf
```

Advanced configuration
----------------------

1. `Swivl.Commenting.DoctrineEntity` sniff provides the following options:

```xml
<rule ref="Swivl.Commenting.DoctrineEntity">
    <properties>
        <property name="concreteTypeToBaseTypeMap" type="array">
            <element key="FeedItemComment" value="CommentInterface"/>
        </property>
        <property name="useDynamicalCalculationForEnumColumnType" value="true"/>
    </properties>
</rule>
```
        
* `concreteTypeToBaseTypeMap` - when column is mapped to concrete type FeedItemComment, but modification 
methods are type hinted with base class CommentInterface
* `useDynamicalCalculationForEnumColumnType` - when enum is mapped to some PHP type.
For example, `type="auth_enum_type"` is mapped to PHP AuthType.
