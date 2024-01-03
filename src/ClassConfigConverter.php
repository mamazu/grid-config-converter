<?php

namespace Mamazu\ConfigConverter;

use InvalidArgumentException;
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
use PhpParser\Node\Stmt\UseUse;
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
use Sylius\Bundle\GridBundle\Grid\AbstractGrid;
use Sylius\Bundle\GridBundle\Grid\ResourceAwareGridInterface;
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

    public function setFunctional(): void
    {
        $this->functional = true;
    }

    public function makeQuiet(): void
    {
        $this->verbose = false;
    }

    public function convert(string $fileName, string $outputDirectory): void
    {
        $outputDirectory = realpath($outputDirectory);
        if ($outputDirectory === false) {
            throw new InvalidArgumentException('Invalid output directory: '.$outputDirectory);
        }

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
                printf('EXCEPTION "%s" on grid "%s": %s%s', get_class($e), $gridName, $e->getMessage(), PHP_EOL);
                continue;
            }
            $new_content = $this->codeOutputter->printCode($phpCode);

            $newFileName = $className . '.php';
            $newFilePath = $outputDirectory. DIRECTORY_SEPARATOR . $newFileName;
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
            new Node\Stmt\DeclareDeclare(new Identifier('strict_types'), new Node\Scalar\LNumber(1))
        ]);
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
            SubItemActionGroup::class,
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
            ResourceAwareGridInterface::class,
        ]));

        $resourceClass = $gridConfiguration['driver']['options']['class'] ?? self::NO_CLASS;
        $isClassName = false;
        if (strpos($resourceClass, '%') !== false || strpos($resourceClass, 'expr:param') !== false) {
            trigger_error('You are using the parameter based syntax which does not work. Either provide a class directly in the getResourceClass or pass it by the constructor.',
                E_USER_WARNING);
        } elseif ($resourceClass !== self::NO_CLASS) {
            $phpNodes[] = new Use_([new UseUse(new Name($resourceClass))]);
            $isClassName = true;
            $part = explode('\\', $resourceClass);
            $resourceClass = end($part);
        }

        if (!$this->functional) {
            $className = ucfirst(preg_replace_callback('#_\w#', static fn($a) => strtoupper($a[0][1]), $gridName));

            $phpNodes[] = new Class_(
                new Identifier($className),
                [
                    'extends' => new Identifier('AbstractGrid'),
                    'implements' => [new Identifier('ResourceAwareGridInterface')],
                    'stmts' => [
                        $this->createStaticFunction('getName', $gridName),
                        $this->createGridBuildFunction($gridConfiguration),
                        $this->createResourceFunction($resourceClass, $isClassName),
                    ],
                ]
            );
        } else {
            $className = $gridName;
            $phpNodes[] = $this->createFunction($gridConfiguration, $resourceClass, $gridName);
        }

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

    public function createGridBuildFunction(array $configuration): Node
    {
        $gridBuilder = new Variable('gridBuilder');
        return new ClassMethod(
            new Identifier('buildGrid'),
            [
                'flags' => Class_::MODIFIER_PUBLIC,
                'returnType' => 'void',
                'params' => [
                    new Param(new Variable('gridBuilder'), null, 'GridBuilderInterface'),
                ],
                'stmts' => [$this->gridBuilder->getGridBuilderBody($gridBuilder, $configuration)],
            ]
        );
    }

    public function createFunction(array $configuration, string $resourceClass, $gridName): Node
    {
        $gridBuilder = new Expr\StaticCall(new Name('GridBuilder'), 'create', [
            new String_($gridName),
            new String_($resourceClass),
        ]);

        $parameter = new Variable('grid');

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
            static fn(string $classToUse) => new Use_([new UseUse(new Name($classToUse))]),
            $useStatement
        );
    }

    private function createResourceFunction(string $returnValue, bool $isClassName): Node
    {
        $expr = $isClassName ? new Expr\ConstFetch(new Name($returnValue . '::class')) : new String_($returnValue);
        return new ClassMethod(
            new Identifier('getResourceClass'),
            [
                'flags' => Class_::MODIFIER_PUBLIC,
                'returnType' => 'string',
                'stmts' => [
                    $returnValue === self::NO_CLASS ? new Throw_(new Expr\New_(new Name('\InvalidArgumentException'),
                        ['arguments' => $expr])) : new Return_($expr),
                ],
            ]
        );
    }

}
