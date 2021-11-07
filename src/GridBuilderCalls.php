<?php

namespace Mamazu\ConfigConverter;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;

class GridBuilderCalls
{
    use CommonConverterTrait;
    use ValueConverterTrait;

    private FilterConverter $filterConverter;
    private FieldConverter $fieldConverter;

    public function __construct()
    {
        $this->filterConverter = new FilterConverter();
        $this->fieldConverter = new FieldConverter();
    }

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
                    $this->convertValue($field),
                    $this->convertValue($sorting),
                ]);
            }
            unset($gridConfiguration['sorting']);
        }

        $this->convertToFunctionCall($gridBuilder, $gridConfiguration, 'limits');

        // Handle the fields
        if (array_key_exists('fields', $gridConfiguration)) {
            foreach ($gridConfiguration['fields'] as $fieldName => $fieldConfig) {
                $gridBuilder = $this->fieldConverter->convertField($gridBuilder, $fieldName, $fieldConfig);
            }
            unset($gridConfiguration['fields']);
        }

        // Handle filters
        if (array_key_exists('filters', $gridConfiguration)) {
            foreach ($gridConfiguration['filters'] as $filterName => $filterConfig) {
                $gridBuilder = $this->filterConverter->convertFilter($gridBuilder, $filterName, $filterConfig);
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
                        new Arg(new Node\Expr\StaticCall(
                            new Name($mappings[$type]),
                            'create',
                            $this->convertActionsToFunctionParameters($configuredTypes)
                        )),
                    ]
                );
            }
            unset($gridConfiguration['actions']);
        }

        $this->checkUnconsumedConfiguration('.', $gridConfiguration);

        return new Expression($gridBuilder);
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
            $this->convertToFunctionCall($field, $configuration, 'icon');
            $this->convertToFunctionCall($field, $configuration, 'enabled');
            $this->convertToFunctionCall($field, $configuration, 'position');
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
            foreach ($driverConfiguration['options'] as $option => $optionValue) {
                $gridBuilder = new MethodCall($gridBuilder, 'setDriverOption', [
                    $this->convertValue($option),
                    $this->convertValue($optionValue)
                ]);
            }
            unset($driverConfiguration['options']);
        }

        $this->checkUnconsumedConfiguration('driver', $driverConfiguration);

        return $gridBuilder;
    }

    public function handleRepositoryConfiguration(Expr $gridBuilder, array $configuration): Expr
    {
        $setRepositoryMethodArguments = [
            $this->convertValue($configuration['method']),
        ];
        unset($configuration['method']);

        if (array_key_exists('arguments', $configuration)) {
            $setRepositoryMethodArguments[] = $this->convertValue($configuration['arguments']);
            unset($configuration['arguments']);
        }

        $this->checkUnconsumedConfiguration('driver.repository', $configuration);

        return new MethodCall($gridBuilder, 'setRepositoryMethod', $setRepositoryMethodArguments);
    }
}
