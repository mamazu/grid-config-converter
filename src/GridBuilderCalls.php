<?php

namespace Mamazu\ConfigConverter;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Throw_;

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

    public function getGridBuilderBody(Expr $gridBuilder, array $gridConfiguration): Node\Stmt
    {
        if (array_key_exists('driver', $gridConfiguration)) {
            $gridBuilder = $this->handleDriver($gridBuilder, $gridConfiguration['driver']);
            unset($gridConfiguration['driver']);
        }

        // Handle extends
        if (array_key_exists('extends', $gridConfiguration)) {
            $gridBuilder = new MethodCall($gridBuilder, 'extends', [
                $this->convertValue($gridConfiguration['extends']),
            ]);
            unset($gridConfiguration['extends']);
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
            $actions = $gridConfiguration['actions'];
            if (is_array($actions) || $actions instanceof \iterable) {
                foreach ($actions as $type => $configuredTypes) {
                    $mappings = [
                        'main' => 'MainActionGroup',
                        'item' => 'ItemActionGroup',
                        'subitem' => 'SubItemActionGroup',
                        'bulk' => 'BulkActionGroup',
                    ];

                    [$add, $remove] = $this->convertActionsToFunctionParameters($configuredTypes);
                    $gridBuilder = new MethodCall(
                        $gridBuilder,
                        'addActionGroup',
                        [
                            new Arg(new Node\Expr\StaticCall(
                                new Name($mappings[$type]),
                                'create',
                                $add
                            )),
                        ]
                    );
                    foreach ($remove as $item) {
                        $gridBuilder = new MethodCall(
                            $gridBuilder,
                            'removeAction',
                            [
                                new Arg(new Node\Scalar\String_($item)),
                                new Arg(new Node\Scalar\String_($type)),
                            ]
                        );
                    }
                }
            }
            unset($gridConfiguration['actions']);
        }

        $this->checkUnconsumedConfiguration('.', $gridConfiguration);

        if ($gridBuilder instanceof Expr\Variable && $gridBuilder->name === 'gridBuilder') {
            return new Throw_(
                new Expr\New_(new Name(
                    '\InvalidArgumentException'),
                    ['arguments' => new Node\Scalar\String_('No configuration for this grid')]
                )
            );
        }
        return new Expression($gridBuilder);
    }

    /** * @return array<Node\Expr> */
    public function convertActionsToFunctionParameters(array $actions): array
    {
        $removedField = [];
        $field = [];
        foreach ($actions as $actionName => $actionConfiguration) {
            if (($actionConfiguration['enabled'] ?? true) === false) {
                $removedField[] = $actionName;
                continue;
            }
            switch ($actionConfiguration['type']) {
                case 'create':
                    $currentField = new Node\Expr\StaticCall(new Name('CreateAction'), 'create');
                    break;
                case 'show':
                    $currentField = new Node\Expr\StaticCall(new Name('ShowAction'), 'create');
                    break;
                case 'delete':
                    $currentField = new Node\Expr\StaticCall(new Name('DeleteAction'), 'create');
                    break;
                case 'update':
                    $currentField = new Node\Expr\StaticCall(new Name('UpdateAction'), 'create');
                    break;
                default:
                    $currentField = new Node\Expr\StaticCall(new Name('Action'), 'create', [
                        $this->convertValue($actionName),
                        $this->convertValue($actionConfiguration['type'])
                    ]);
            }

            $this->convertToFunctionCall($currentField, $actionConfiguration, 'label');
            $this->convertToFunctionCall($currentField, $actionConfiguration, 'icon');
            $this->convertToFunctionCall($currentField, $actionConfiguration, 'enabled');
            $this->convertToFunctionCall($currentField, $actionConfiguration, 'position');
            $this->convertToFunctionCall($currentField, $actionConfiguration, 'options');

            $field[]=$currentField;
        }

        return [$field, $removedField];
    }

    private function handleDriver(Expr $gridBuilder, array $driverConfiguration): Expr
    {
        if (array_key_exists('name', $driverConfiguration)) {
            if ($driverConfiguration['name'] !== 'doctrine/orm') {
                $gridBuilder = new MethodCall(
                    $gridBuilder,
                    'setDriver',
                    [$this->convertValue($driverConfiguration['name'])]
                );
            }
            unset($driverConfiguration['name']);
        }

        if (array_key_exists('repository', $driverConfiguration['options'])) {
            $gridBuilder = $this->handleRepositoryConfiguration(
                $gridBuilder,
                $driverConfiguration['options']['repository']
            );
            unset($driverConfiguration['options']['repository']);
        }

        if (array_key_exists('options', $driverConfiguration)) {
            foreach ($driverConfiguration['options'] as $option => $optionValue) {
                if ($option === 'class') {
                    continue;
                }
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
