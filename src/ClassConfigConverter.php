<?php

use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Return_;
use Sylius\Bundle\GridBundle\Builder\Action\Action;
use Sylius\Bundle\GridBundle\Builder\Action\DeleteAction;
use Sylius\Bundle\GridBundle\Builder\Action\ShowAction;
use Sylius\Bundle\GridBundle\Builder\Action\UpdateAction;
use Sylius\Bundle\GridBundle\Builder\Field\Field;
use Sylius\Bundle\GridBundle\Builder\Filter\Filter;
use Sylius\Bundle\GridBundle\Builder\GridBuilderInterface;
use Sylius\Bundle\GridBundle\Builder\ActionGroup\MainActionGroup;
use Sylius\Bundle\GridBundle\Builder\ActionGroup\ItemActionGroup;
use Sylius\Bundle\GridBundle\Builder\ActionGroup\BulkActionGroup;
use Sylius\Bundle\GridBundle\Builder\Field\DateTimeField;
use Sylius\Bundle\GridBundle\Builder\Field\StringField;
use Sylius\Bundle\GridBundle\Builder\Field\TwigField;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;
use Sylius\Bundle\GridBundle\Grid\AbstractGrid;
use Symfony\Component\Yaml\Yaml;

class ClassConfigConverter
{
    private ?string $namespace = null;

    public function __construct()
    {
        $this->prettyPrinter = new PhpParser\PrettyPrinter\Standard();
    }

    public function setNamespace(string $namespace): void
    {
        $this->namespace = $namespace;
    }

    public function convert(string $fileName): void
    {
        $allGrids = Yaml::parse(file_get_contents($fileName))['sylius_grid']['grids'] ?? [];

        if(!is_array($allGrids)) {
            throw new InvalidArgumentException('Parsing of the file was either not successful or the file does not contain a valid configuration');
        }

        if (count($allGrids) === 0) {
            throw new InvalidArgumentException('Could not find any grids in the parsed file');
        }

        foreach ($allGrids as $gridName => $gridConfiguration) {
            [$className, $phpCode] = $this->handleGrid($gridName, $gridConfiguration);
            $new_content = "<?php\n".$this->prettyPrinter->prettyPrint($phpCode)."\n";

            $newFileName = $className.'.php';
            echo "==============================$newFileName================".PHP_EOL;
            echo $new_content;
            file_put_contents($newFileName, $new_content);
        }
    }

    public function handleGrid(string $gridName, array $gridConfiguration): array
    {
        $phpNodes = [];
        if ($this->namespace) {
            $phpNodes[] = new Node\Stmt\Namespace_(new Name($this->namespace));
        }
        $phpNodes = array_merge($phpNodes, $this->generateUseStatements([
            AbstractGrid::class,
            Filter::class,
            Field::class,
            GridBuilderInterface::class,
            MainActionGroup::class,
            ItemActionGroup::class,
            BulkActionGroup::class,
            Action::class,
            ShowAction::class,
            UpdateAction::class,
            DeleteAction::class,
            DateTimeField::class,
            StringField::class,
            TwigField::class,
        ]));

        $resourceClass = $gridConfiguration['driver']['options']['class'];
        unset($gridConfiguration['driver']['options']['class']);

        $className = ucfirst(preg_replace_callback('#_\w#', static fn($a) => strtoupper($a[0][1]), $gridName));

        $gridNode = new Class_(
            new Identifier($className),
            [
                'extends' => new Identifier('AbstractGrid'),
                'stmts' => [
                    $this->createStaticFunction('getName', $gridName),
                    $this->createStaticFunction('getResourceClass', $resourceClass),
                    $this->addBuildGridFunction($gridConfiguration),
                ],
            ],
            []
        );

        $phpNodes[] = $gridNode;

        return [$className, $phpNodes];
    }

    private function createStaticFunction(string $functionName, string $returnValue): Node
    {
        return new ClassMethod(
            new Identifier($functionName),
            [
                'flags' => Class_::MODIFIER_PUBLIC | Class_::MODIFIER_STATIC,
                'returnType' => 'string',
                'stmts' => [
                    new Return_(new String_($returnValue)),
                ],
            ]
        );
    }

    private function addBuildGridFunction(array $gridConfiguration): Node
    {
        $gridBuilder = new Variable('gridBuilder');
        $statements = [];

        if (array_key_exists('driver', $gridConfiguration)) {
            $statements = array_merge($statements, $this->handleDriver($gridBuilder, $gridConfiguration['driver']));
            unset($gridConfiguration['driver']);
        }

        // Handle the sorting
        if (array_key_exists('sorting', $gridConfiguration)) {
            foreach ($gridConfiguration['sorting'] as $field => $sorting) {
                $statements[] = new Expression(new MethodCall($gridBuilder, 'addOrderBy', [
                    new String_($field),
                    new String_($sorting),
                ]));
            }
            unset($gridConfiguration['sorting']);
        }

        $clonedGridBuilder = clone $gridBuilder;
        $this->convertToFunctionCall($clonedGridBuilder, $gridConfiguration, 'limits');
        $statements[] = new Expression($clonedGridBuilder);

        // Handle the fields
        if (array_key_exists('fields', $gridConfiguration)) {
            foreach ($gridConfiguration['fields'] as $fieldName => $fieldConfig) {
                $statements[] = $this->handleField($gridBuilder, $fieldName, $fieldConfig);
            }
            unset($gridConfiguration['fields']);
        }

        // Handle filters
        if (array_key_exists('filters', $gridConfiguration)) {
            foreach ($gridConfiguration['filters'] as $filterName => $filterConfig) {
                $statements[] = $this->handleFilter($gridBuilder, $filterName, $filterConfig);
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

                $statements[] =
                    new Expression(
                        new MethodCall(
                            $gridBuilder,
                            'addActionGroup',
                            [
                                new Node\Expr\StaticCall(
                                    new Name($mappings[$type]),
                                    'create',
                                    $this->convertActionsToFunctionParameters($configuredTypes)
                                ),
                            ]
                        ));
            }
            unset($gridConfiguration['actions']);
        }

        $unusedKeys = array_keys($gridConfiguration);
        if (count($unusedKeys) !== 0) {
            throw new InvalidArgumentException('There are unhandled keys: '.implode(', ', $unusedKeys));
        }

        return new ClassMethod(
            new Identifier('buildGrid'),
            [
                'flags' => Class_::MODIFIER_PUBLIC,
                'returnType' => 'void',
                'params' => [
                    new Param($gridBuilder, null, 'GridBuilderInterface'),
                ],
                'stmts' => $statements,
            ]
        );
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

        return new Expression(new MethodCall($gridBuilder, 'addField', [$field]));
    }

    public function handleFilter(Expr $gridBuilder, string $filterName, array $configuration): Node
    {
        $filter = new Node\Expr\StaticCall(new Name('Filter'), 'create', [
            new String_($filterName),
            new String_($configuration['type']),
        ]);
        unset($configuration['type']);

        $this->convertToFunctionCall($field, $configuration, 'enabled');
        $this->convertToFunctionCall($filter, $configuration, 'label');
        $this->convertToFunctionCall($filter, $configuration, 'options');
        $this->convertToFunctionCall($filter, $configuration, 'form_options');

        $unusedFields = array_keys($configuration);
        if (count($unusedFields) !== 0) {
            throw new InvalidArgumentException('There are unused fields: '.implode(', ', $unusedFields));
        }

        return new Expression(new MethodCall($gridBuilder, 'addFilter', [$filter]));
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

    private function handleDriver(Variable $gridBuilder, array $driverConfiguration): array
    {
        // TODO: Be more thorough with the driver options
        $statements = [];

        if (array_key_exists('name', $driverConfiguration)) {
            $statements[] =
                new Expression(new MethodCall($gridBuilder, 'setDriver', [new String_($driverConfiguration['name'])]));
            unset($driverConfiguration['name']);
        }

        if (array_key_exists('repository', $driverConfiguration['options'])) {
            $statements[] =
                $this->handleRepositoryConfiguration($gridBuilder, $driverConfiguration['options']['repository']);
            unset($driverConfiguration['options']['repository']);
        }

        if (array_key_exists('repository', $driverConfiguration['options'])) {
            $statements[] =
                $this->handleRepositoryConfiguration($gridBuilder, $driverConfiguration['options']['repository']);
            unset($driverConfiguration['options']['repository']);
        }

        if (array_key_exists('options', $driverConfiguration)) {
            foreach($driverConfiguration['options'] as $option => $optionValue) {
                $statements[] = new Expression(
                    new MethodCall($gridBuilder, 'setDriverOption', [new String_($option), $this->convertValue($optionValue)]));
            }
            unset($driverConfiguration['options']);
        }

        $unhandledElements = array_keys($driverConfiguration);
        if (count($unhandledElements) > 0) {
            throw new InvalidArgumentException('Unhandled arguments: '.print_r($driverConfiguration, true));
        }

        return $statements;
    }

    public function handleRepositoryConfiguration(Variable $gridBuilder, array $configuration): Node
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

        return new Expression(new MethodCall($gridBuilder, 'setRepositoryMethod', $setRepositoryMethodArguments));
    }

    private function convertToFunctionCall(&$field, array &$configuration, string $fieldName)
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
