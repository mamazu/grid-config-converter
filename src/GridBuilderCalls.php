<?php

namespace Mamazu\ConfigConverter;

use InvalidArgumentException;
use PhpParser\Node\Expr;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;

class GridBuilderCalls
{
    public function getGridBuilderBody(Expr $gridBuilder, array $gridConfiguration): Expression
    {
        if (array_key_exists('driver', $gridConfiguration)) {
            $gridBuilder = $this->handleDriver($gridBuilder, $gridConfiguration['driver']);
            unset($gridConfiguration['driver']);
        }

        // Handle the sorting
        if (array_key_exists('sorting', $gridConfiguration)) {
            foreach ($gridConfiguration['sorting'] as $field => $sorting) {
                $gridBuilder = new MethodCall($gridBuilder, 'addOrderBy', [
                    new String_($field),
                    new String_($sorting),
                ]);
            }
            unset($gridConfiguration['sorting']);
        }

        $this->convertToFunctionCall($gridBuilder, $gridConfiguration, 'limits');

        // Handle the fields
        if (array_key_exists('fields', $gridConfiguration)) {
            foreach ($gridConfiguration['fields'] as $fieldName => $fieldConfig) {
                $gridBuilder = $this->handleField($gridBuilder, $fieldName, $fieldConfig);
            }
            unset($gridConfiguration['fields']);
        }

        // Handle filters
        if (array_key_exists('filters', $gridConfiguration)) {
            foreach ($gridConfiguration['filters'] as $filterName => $filterConfig) {
                $gridBuilder = $this->handleFilter($gridBuilder, $filterName, $filterConfig);
            }
            unset($gridConfiguration['filters']);
        }

        // Handle actions
        if (array_key_exists('actions', $gridConfiguration)) {
            foreach ($gridConfiguration['actions'] as $type => $configuredTypes) {
                $mappings = [
                    'main' => 'MainActionGroup',
                    'item' => 'ItemActionGroup',
                    'bulk' => 'BulkActionGroup',
                ];

                   $gridBuilder = new MethodCall(
                        $gridBuilder,
                        'addActionGroup',
                        [
                            new Node\Expr\StaticCall(
                                new Name($mappings[$type]),
                                'create',
                                $this->convertActionsToFunctionParameters($configuredTypes)
                            ),
                        ]
                    );
            }
            unset($gridConfiguration['actions']);
        }

        $unusedKeys = array_keys($gridConfiguration);
        if (count($unusedKeys) !== 0) {
            throw new InvalidArgumentException('There are unhandled keys: '.implode(', ', $unusedKeys));
        }

        return new Expression($gridBuilder);
    }

    private function handleField(Expr $gridBuilder, string $fieldName, array $fieldConfig): Node
    {
        switch ($fieldConfig['type']) {
            case 'datetime':
                $field = new Node\Expr\StaticCall(new Name('DateTimeField'), 'create', [
                    new String_($fieldName),
                ]);
                break;
            case 'string':
                $field = new Node\Expr\StaticCall(new Name('StringField'), 'create', [
                    new String_($fieldName),
                ]);
                break;
            case 'twig':
                $field = new Node\Expr\StaticCall(new Name('TwigField'), 'create', [
                    new String_($fieldName),
                    new String_($fieldConfig['options']['template']),
                ]);
                unset($fieldConfig['options']['template']);
                break;
            default:
                $field = new Node\Expr\StaticCall(new Name('Field'), 'create', [
                    new String_($fieldName),
                    new String_($fieldConfig['type']),
                ]);
        }
        unset($fieldConfig['type']);

        $this->convertToFunctionCall($field, $fieldConfig, 'enabled');
        $this->convertToFunctionCall($field, $fieldConfig, 'label');
        $this->convertToFunctionCall($field, $fieldConfig, 'position');
        $this->convertToFunctionCall($field, $fieldConfig, 'path');

        if (array_key_exists('sortable', $fieldConfig)) {
            $path = $fieldConfig['sortable'];

            $arguments = [
                new ConstFetch(new Name('true')),
            ];
            if ($path !== null) {
                $arguments[] = new String_($path);
            }
            $field = new MethodCall($field, 'setSortable', $arguments);
            unset($fieldConfig['sortable']);
        }

        if (isset($fieldConfig['options'])) {
            if (count($fieldConfig['options']) > 0) {
                $field = new MethodCall($field, 'setOptions', [$this->convertValue($fieldConfig['options'])]);
            }
            unset($fieldConfig['options']);
        }

        $unusedFields = array_keys($fieldConfig);
        if (count($unusedFields) !== 0) {
            throw new InvalidArgumentException('There are unused fields: '.implode(', ', $unusedFields));
        }

        return new MethodCall($gridBuilder, 'addField', [$field]);
    }

    public function handleFilter(Expr $gridBuilder, string $filterName, array $configuration): Expr
    {
        $filter = new Node\Expr\StaticCall(new Name('Filter'), 'create', [
            new String_($filterName),
            new String_($configuration['type']),
        ]);
        unset($configuration['type']);

        $this->convertToFunctionCall($filter, $configuration, 'enabled');
        $this->convertToFunctionCall($filter, $configuration, 'label');
        $this->convertToFunctionCall($filter, $configuration, 'options');
        $this->convertToFunctionCall($filter, $configuration, 'form_options');

        $unusedFields = array_keys($configuration);
        if (count($unusedFields) !== 0) {
            throw new InvalidArgumentException('There are unused fields: '.implode(', ', $unusedFields));
        }

        return new MethodCall($gridBuilder, 'addFilter', [$filter]);
    }

    public function convertValue($value): Node
    {
        if (is_string($value)) {
            return new String_($value);
        }

        if (is_bool($value)) {
            return new ConstFetch(new Name($value ? 'true' : 'false'));
        }

        if ($value === null) {
            return new ConstFetch(new Name('null'));
        }
        if (is_int($value)) {
            return new ConstFetch(new Name((string)$value));
        }

        if (is_array($value)) {
            $items = [];
            foreach ($value as $key => $subValue) {
                $val = $this->convertValue($subValue);
                $convertedKey = null;
                if (is_string($key)) {
                    $convertedKey = new String_($key);
                }
                $items[] = new Node\Expr\ArrayItem($val, $convertedKey);
            }

            return new Array_($items, ['kind' => Array_::KIND_SHORT]);
        }
        throw new InvalidArgumentException('Could not convert datatype: '.get_debug_type($value));
    }

    /** * @return array<Node\Expr> */
    public function convertActionsToFunctionParameters(array $actions): array
    {
        $handleCustomGrid = function (string $actionName, array $configuration): Node {
            $field = new Node\Expr\StaticCall(new Name('Action'), 'create', [
                $this->convertValue($actionName),
                $this->convertValue($configuration['type'])
            ]);
            $this->convertToFunctionCall($field, $configuration, 'label');
            $this->convertToFunctionCall($field, $configuration, 'options');

            return $field;
        };

        $field = [];
        foreach ($actions as $actionName => $actionConfiguration) {
            switch ($actionConfiguration['type']) {
                case 'show':
                    $field[] = new Node\Expr\StaticCall(new Name('ShowAction'), 'create');
                    break;
                case 'delete':
                    $field[] = new Node\Expr\StaticCall(new Name('DeleteAction'), 'create');
                    break;
                case 'update':
                    $field[] = new Node\Expr\StaticCall(new Name('UpdateAction'), 'create');
                    break;
                default:
                    $field[] = $handleCustomGrid($actionName, $actionConfiguration);
            }
        }

        return $field;
    }

    private function generateUseStatements(array $useStatement): array
    {
        return array_map(
            static fn(string $classToUse) => new Use_([new UseUse(new Name($classToUse))]),
            $useStatement
        );
    }

    private function handleDriver(Expr $gridBuilder, array $driverConfiguration): Expr
    {
        if (array_key_exists('name', $driverConfiguration)) {
            $gridBuilder = new MethodCall($gridBuilder, 'setDriver', [$this->convertValue($driverConfiguration['name'])]);
            unset($driverConfiguration['name']);
        }

        if (array_key_exists('repository', $driverConfiguration['options'])) {
            $gridBuilder = $this->handleRepositoryConfiguration($gridBuilder, $driverConfiguration['options']['repository']);
            unset($driverConfiguration['options']['repository']);
        }

        if (array_key_exists('options', $driverConfiguration)) {
            foreach($driverConfiguration['options'] as $option => $optionValue) {
                $gridBuilder = new MethodCall($gridBuilder, 'setDriverOption', [new String_($option), $this->convertValue($optionValue)]);
            }
            unset($driverConfiguration['options']);
        }

        $unhandledElements = array_keys($driverConfiguration);
        if (count($unhandledElements) > 0) {
            throw new InvalidArgumentException('Unhandled arguments: '.print_r($driverConfiguration, true));
        }

        return $gridBuilder;
    }

    public function handleRepositoryConfiguration(Expr $gridBuilder, array $configuration): Expr
    {
        $setRepositoryMethodArguments = [
            new String_($configuration['method']),
        ];
        unset($configuration['method']);

        if (array_key_exists('arguments', $configuration)) {
            $setRepositoryMethodArguments[] = $this->convertValue($configuration['arguments']);
            unset($configuration['arguments']);
        }

        $unusedFields = array_keys($configuration);
        if (count($unusedFields) !== 0) {
            throw new InvalidArgumentException('There are unused fields: '.implode(', ', $unusedFields));
        }

        return new MethodCall($gridBuilder, 'setRepositoryMethod', $setRepositoryMethodArguments);
    }

    private function convertToFunctionCall(Expr &$field, array &$configuration, string $fieldName): void
    {
        if (!isset($configuration[$fieldName])) {
            return;
        }

        // converting form_options to setFormOptions
        $methodName = 'set'.ucfirst(preg_replace_callback('#_\w#', static fn($a) => strtoupper($a[0][1]), $fieldName));
        $field = new MethodCall($field, $methodName, [$this->convertValue($configuration[$fieldName])]);
        unset($configuration[$fieldName]);
    }
}
