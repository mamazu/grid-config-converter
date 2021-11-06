<?php

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
use Symfony\Component\Yaml\Yaml;

class ConfigConverter
{
    public function __construct()
    {
        $this->prettyPrinter = new PhpParser\PrettyPrinter\Standard();
    }

    public function convert(string $fileName): void
    {
        $phpCode = $this->convertConfigToPhp(Yaml::parse(file_get_contents($fileName)));

        $new_content = "<?php\n".$this->prettyPrinter->prettyPrint($phpCode)."\n";

        echo $new_content;
        $newFileName = str_replace('yml', 'php', $fileName);
        file_put_contents($newFileName, $new_content);
    }

    public function convertConfigToPhp(array $gridConfiguration): array
    {
        $grids = $gridConfiguration['sylius_grid']['grids'];

        $phpNodes = $this->generateUseStatements([
            'Sylius\Bundle\GridBundle\AbstractGrid',
            'Sylius\Bundle\GridBundle\Builder\Field',
            'Sylius\Bundle\GridBundle\Builder\Filter',
            'Sylius\Bundle\GridBundle\Builder\GridBuilderInterface',
            'Sylius\Bundle\GridBundle\Builder\ActionGroup\MainActionGroup',
            'Sylius\Bundle\GridBundle\Builder\ActionGroup\ItemActionGroup',
            'Sylius\Bundle\GridBundle\Builder\ActionGroup\BulkActionGroup',
            'Sylius\Bundle\GridBundle\Builder\Field\DateTimeField',
            'Sylius\Bundle\GridBundle\Builder\Field\StringField',
            'Sylius\Bundle\GridBundle\Builder\Field\TwigFiel',
        ]);

        foreach ($grids as $gridName => $grid) {
            $resourceClass = $grid['driver']['options']['class'];

            $className = ucfirst(preg_replace_callback('#_\w#', static fn($a) => strtoupper($a[0][1]), $gridName));

            $gridNode = new \PhpParser\Node\Stmt\Class_(
                new Identifier($className),
                [
                    'extends' => new Identifier('AbstractGrid'),
                    'stmts' => [
                        $this->createStaticFunction('getName', $gridName),
                        $this->createStaticFunction('getResourceClass', $resourceClass),
                        $this->addBuildGridFunction($grid),
                    ],
                ],
                []
            );

            $phpNodes[] = $gridNode;
        }

        return $phpNodes;
    }

    private function createStaticFunction(string $functionName, string $returnValue): \PhpParser\Node
    {
        return new ClassMethod(
            new Identifier($functionName),
            [
                'flags' => Class_::MODIFIER_PUBLIC | Class_::MODIFIER_STATIC,
                'returnType' => 'string',
                'stmts' => [
                    new \PhpParser\Node\Stmt\Return_(new \PhpParser\Node\Scalar\String_($returnValue)),
                ],
            ]
        );
    }

    private function addBuildGridFunction(array $gridConfiguration): \PhpParser\Node
    {
        $gridBuilder = new Variable('gridBuilder');

        // TODO: Be more thorough with the driver options
        $statements = [];
        $statements[] = new Expression(
            new MethodCall($gridBuilder, 'setRepositoryMethod',
                [
                    new String_($gridConfiguration['driver']['options']['repository']['method']),
                ]
            ));
        unset($gridConfiguration['driver']);

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

        // Handle limits
        if (array_key_exists('limits', $gridConfiguration)) {
            $statements[] = new Expression(new MethodCall($gridBuilder, 'setLimits', [
                $this->convertArray($gridConfiguration['limits']),
            ]));
            unset($gridConfiguration['limits']);
        }

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
                                $this->handleAction($gridBuilder, $configuredTypes)
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

    private function handleField(Variable $gridBuilder, string $fieldName, array $fieldConfig): Node
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
                ]);
                break;
            default:
                $field = new Node\Expr\StaticCall(new Name('Field'), 'create', [
                    new String_($fieldName),
                    new String_($fieldConfig['type']),
                ]);
        }
        unset($fieldConfig['type']);

        if (isset($fieldConfig['label'])) {
            $field = new MethodCall($field, 'addLabel', [new String_($fieldConfig['label'])]);
            unset($fieldConfig['label']);
        }

        if (isset($fieldConfig['path'])) {
            $field = new MethodCall($field, 'setPath', [new String_($fieldConfig['path'])]);
            unset($fieldConfig['path']);
        }

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
            $field = new MethodCall($field, 'setOptions', [$this->convertArray($fieldConfig['options'])]);
            unset($fieldConfig['options']);
        }

        $unusedFields = array_keys($fieldConfig);
        if (count($unusedFields) !== 0) {
            throw new InvalidArgumentException('There are unused fields: '.implode(', ', $unusedFields));
        }

        return new Expression(new MethodCall($gridBuilder, 'addField', [$field]));
    }

    public function handleFilter(Variable $gridBuilder, string $filterName, array $configuration): Node
    {
        $field = new Node\Expr\StaticCall(new Name('Filter'), 'fromNameAndType', [
            new String_($filterName),
            new String_($configuration['type']),
        ]);
        unset($configuration['type']);

        if (isset($configuration['enabled'])) {
            $field = new MethodCall($field, 'setEnabled', [$this->convertBool($configuration['enabled'])]);
            unset($configuration['setEnabled']);
        }

        if (isset($configuration['label'])) {
            $field = new MethodCall($field, 'addLabel', [new String_($configuration['label'])]);
            unset($configuration['label']);
        }

        if (isset($configuration['options'])) {
            $field = new MethodCall($field, 'setOptions', [$this->convertArray($configuration['options'])]);
            unset($configuration['options']);
        }

        if (isset($configuration['form_options'])) {
            $field = new MethodCall($field, 'setFormOptions', [$this->convertArray($configuration['form_options'])]);
            unset($configuration['form_options']);
        }

        $unusedFields = array_keys($configuration);
        if (count($unusedFields) !== 0) {
            throw new InvalidArgumentException('There are unused fields: '.implode(', ', $unusedFields));
        }

        return new Expression(new MethodCall($gridBuilder, 'addField', [$field]));
    }

    public function convertBool(bool $value): Node
    {
        return new ConstFetch(new Name($value ? 'true' : 'false'));
    }

    public function convertArray(array $array): Array_
    {
        $items = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $val = $this->convertArray($value);
            } elseif (is_string($value)) {
                $val = new String_($value);
            } elseif (is_bool($value)) {
                $val = $this->convertBool($value);
            } elseif ($value === null) {
                $val = new ConstFetch(new Name('null'));
            } elseif (is_int($value)) {
                $val = new ConstFetch(new Name((string)$value));
            } else {
                throw new InvalidArgumentException('Could not convert datatype: '.get_debug_type($value));
            }
            $items[] = new Node\Expr\ArrayItem($val, new String_($key));
        }

        return new Array_($items, ['kind' => Array_::KIND_SHORT]);
    }

    public function handleAction(Variable $gridBuilder, array $actionConfig): array
    {
        $field = [];
        foreach ($actionConfig as $actionConf) {
            switch ($actionConf['type']) {
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
                    throw new InvalidArgumentException('Could not convert action of type: '.$actionConf['type']);
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
}
