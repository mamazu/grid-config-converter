<?php

namespace Mamazu\ConfigConverter;

use InvalidArgumentException;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Throw_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use Sylius\Bundle\GridBundle\Builder\Action\Action;
use Sylius\Bundle\GridBundle\Builder\Action\CreateAction;
use Sylius\Bundle\GridBundle\Builder\Action\DeleteAction;
use Sylius\Bundle\GridBundle\Builder\Action\ShowAction;
use Sylius\Bundle\GridBundle\Builder\Action\UpdateAction;
use Sylius\Bundle\GridBundle\Builder\ActionGroup\BulkActionGroup;
use Sylius\Bundle\GridBundle\Builder\ActionGroup\ItemActionGroup;
use Sylius\Bundle\GridBundle\Builder\ActionGroup\MainActionGroup;
use Sylius\Bundle\GridBundle\Builder\ActionGroup\SubItemActionGroup;
use Sylius\Bundle\GridBundle\Builder\Field\DateTimeField;
use Sylius\Bundle\GridBundle\Builder\Field\Field;
use Sylius\Bundle\GridBundle\Builder\Field\StringField;
use Sylius\Bundle\GridBundle\Builder\Field\TwigField;
use Sylius\Bundle\GridBundle\Builder\Filter\Filter;
use Sylius\Bundle\GridBundle\Builder\GridBuilder;
use Sylius\Bundle\GridBundle\Builder\GridBuilderInterface;
use Sylius\Bundle\GridBundle\Config\GridConfig;
use Sylius\Component\Grid\Attribute\AsGrid;
use Symfony\Component\Yaml\Yaml;

class ClassConfigConverter
{
    const NO_CLASS = 'To be replaced with the correct class.';
    private ?string $namespace = null;
    private bool $functional = false;
    private bool $verbose = true;
    private CodeOutputter $codeOutputter;
    private GridBuilderCalls $gridBuilder;

    public function __construct()
    {
        $this->codeOutputter = new CodeOutputter();
        $this->gridBuilder = new GridBuilderCalls();
    }

    public function setNamespace(string $namespace): void
    {
        $this->namespace = $namespace;
    }

    public function makeQuiet(): void
    {
        $this->verbose = false;
    }

    public function convert(string $fileName, string $outputDirectory): void
    {
        $outputDirectoryResolved = realpath($outputDirectory);
        if ($outputDirectoryResolved === false) {
            throw new InvalidArgumentException('The output directory is invalid: '.$outputDirectory);
        }
        $outputDirectory = $outputDirectoryResolved;

        $allGrids = Yaml::parse(file_get_contents($fileName))['sylius_grid']['grids'] ?? [];

        if (!is_array($allGrids)) {
            throw new InvalidArgumentException('Parsing of the file was either not successful or the file does not contain a valid configuration');
        }

        if (count($allGrids) === 0) {
            throw new InvalidArgumentException('Could not find any grids in the parsed file');
        }

        /*
         * Print all grids into separate files:
         * Class mode: filename = classname
         * Functional mode: filename = name of the grid
         */
        foreach ($allGrids as $gridName => $gridConfiguration) {
            echo "************* PROCESS GRID $gridName *************" . PHP_EOL;
            try {
                [$className, $phpCode] = $this->handleGrid($gridName, $gridConfiguration);
            } catch (\Throwable $e) {
                printf('EXCEPTION "%s" on grid "%s": %s', get_class($e), $gridName, $e->getMessage());
                continue;
            }
            $new_content = $this->codeOutputter->printCode($phpCode);

            $newFileName = $className . '.php';
            $newFilePath = $outputDirectory . DIRECTORY_SEPARATOR . $newFileName;
            if ($this->verbose) {
                echo "==============================$newFilePath================" . PHP_EOL;
                echo $new_content;
            } else {
                echo "Writing the content to " . $newFilePath . PHP_EOL;
            }
            file_put_contents($newFilePath, $new_content);
        }
    }

    public function handleGrid(string $gridName, array $gridConfiguration): array
    {

        $phpNodes = [];
        $phpNodes[] = new Node\Stmt\Declare_([
            new Node\DeclareItem(new Identifier('strict_types'), new Node\Scalar\Int_(1))
        ]);
        if ($this->namespace) {
            $phpNodes[] = new Node\Stmt\Namespace_(new Name($this->namespace));
        }
        $phpNodes = array_merge($phpNodes, $this->generateUseStatements([
            AsGrid::class,
            Filter::class,
            Field::class,
            GridBuilderInterface::class,
            BulkActionGroup::class,
            GridConfig::class,
            GridBuilder::class,
            Action::class,
            ShowAction::class,
            CreateAction::class,
            UpdateAction::class,
            DeleteAction::class,
            DateTimeField::class,
            StringField::class,
            TwigField::class,
        ]));

        $resourceClass = $gridConfiguration['driver']['options']['class'] ?? self::NO_CLASS;
        $isClassName = false;
        if (class_exists($resourceClass)) {
            $phpNodes[] = new Use_([new UseItem(new Name($resourceClass))]);
            $isClassName = true;
            $part = explode('\\', $resourceClass);
            $resourceClass = end($part);
        }

        if (!$this->functional) {
            $className = ucfirst(preg_replace_callback('#_\w#', static fn($a) => strtoupper($a[0][1]), $gridName));

            $phpNodes[] = new Node\AttributeGroup([
                new Node\Attribute(new Name('AsGrid'), [new Node\Arg(new String_($gridName))]),
            ]);
            $phpNodes[] = new Class_(
                new Identifier($className),
                [
                    'stmts' => [
                        $this->createConstructor($resourceClass),
                        $this->createGridBuildFunction($gridConfiguration),
                    ],
                ]
            );
        } else {
            $className = $gridName;
            $phpNodes[] = $this->createFunction($gridConfiguration, $resourceClass, $gridName);
        }

        return [$className, $phpNodes];
    }

    public function createConstructor(?string $resourceClass): Node
    {
        if ($resourceClass !== null) {
            $default = new String_($resourceClass);
        }

        return new ClassMethod(
            new Identifier('__construct'),
            [
                'flags' => Modifiers::PUBLIC,
                'params' => [
                    new Param(
                        var: new Variable('resourceClass'),
                        type: new Identifier('string'),
                        default: $default,
                        flags: Modifiers::PRIVATE,
                    ),
                ],
                'stmts' => [],
            ]
        );
    }
    public function createGridBuildFunction(array $configuration): Node
    {
        $this->gridBuilder->functionalMode = false;

        $gridBuilder = new Variable('gridBuilder');
        return new ClassMethod(
            new Identifier('__invoke'),
            [
                'flags' => Modifiers::PUBLIC,
                'returnType' => new Identifier('void'),
                'params' => [
                    new Param(
                        var: new Variable('gridBuilder'),
                        type: new Name('GridBuilderInterface'),
                    ),
                ],
                'stmts' => [$this->gridBuilder->getGridBuilderBody($gridBuilder, $configuration)],
            ]
        );
    }

    public function createFunction(array $configuration, string $resourceClass, string $gridName): Node
    {
        $gridBuilder = new Expr\StaticCall(new Name('GridBuilder'), 'create', [
            new String_($gridName),
            new String_($resourceClass),
        ]);

        $parameter = new Variable('grid');
        $this->gridBuilder->functionalMode = true;

        return new Return_(
            new Expr\Closure([
                'static' => true,
                'params' => [
                    new Param($parameter, null, 'GridConfig'),
                ],
                'returnType' => 'void',
                'stmts' => [
                    new Expression(new Expr\MethodCall(
                        $parameter,
                        'addGrid',
                        [$this->gridBuilder->getGridBuilderBody($gridBuilder, $configuration)->expr],
                    )),
                ],
            ])
        );
    }

    private function generateUseStatements(array $useStatement): array
    {
        return array_map(
            static fn(string $classToUse) => new Use_([new UseItem(new Name($classToUse))]),
            $useStatement
        );
    }
}
