<?php

use Mamazu\ConfigConverter\ClassConfigConverter;
use Sylius\Bundle\GridBundle\Grid\InvokableGrid;
use Sylius\Bundle\GridBundle\Provider\ServiceGridProvider;
use Sylius\Bundle\GridBundle\Registry\GridRegistry;
use Sylius\Component\Grid\Attribute\AsGrid;
use Sylius\Component\Grid\Configuration\GridConfigurationExtender;
use Sylius\Component\Grid\Configuration\GridConfigurationRemovalsHandler;
use Sylius\Component\Grid\Configuration\GridConfigurationSortingHandler;
use Sylius\Component\Grid\Definition\ArrayToDefinitionConverter;
use Sylius\Component\Grid\Definition\Grid;
use Sylius\Component\Grid\Provider\ArrayGridProvider;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Yaml\Yaml;

class ParsingTest extends \PHPUnit\Framework\TestCase
{
    public static function getOutputFolder(): string {
        return __DIR__.'/output';
    }
    public function setup(): void {
        chdir(__DIR__);

        mkdir(self::getOutputFolder());
        $builder = new ClassConfigConverter();
        $builder->makeQuiet();
        foreach(new DirectoryIterator('.') as $file) {
            /** @var DirectoryIterator $file */
            if(in_array($file->getExtension(), ['yaml', 'yml'])) {
                $builder->convert($file->getFilename(), self::getOutputFolder());
            }
        }
    }

    public function tearDown(): void
    {
        exec('rm -rf "'.self::getOutputFolder().'"');
    }

    /** @covers  */
    public function testConfigurationForOrder()
    {
        $yaml = Yaml::parse(file_get_contents('order.yml'))['sylius_grid']['grids']['sylius_admin_order'];

        include self::getOutputFolder().'/SyliusAdminOrder.php';
        $orderGrid = new \SyliusAdminOrder();

        $this->assertGridEquals('sylius_admin_order', $yaml, $orderGrid);
    }

    /** @covers  */
    public function testConfigurationForAdvancedConfig()
    {
        $yaml = Yaml::parse(file_get_contents('advanced_configuration.yml'))['sylius_grid']['grids']['foo'];

        include self::getOutputFolder().'/Foo.php';
        $orderGrid = new \Foo();

        $this->assertGridEquals('foo', $yaml, $orderGrid);
    }

    private function assertGridEquals(string $gridName, array $yaml, object $grid): void {
        $this->assertEquals(
            $this->buildArrayGridDefinition($gridName, $yaml),
            $this->buildServiceGridDefinition($gridName, $grid),
        );
    }

    private function buildServiceGridDefinition(string $gridName, object $grid): Grid
    {
        $asGrid = $this->resolveAsGrid($grid::class);

        $locatedGrids = [$gridName => fn() => new InvokableGrid(
            code: $grid,
            name: $asGrid->name ?? $grid::class,
            class: $grid::class,
            resourceClass: $asGrid->resourceClass,
            buildMethod: $asGrid->buildMethod,
            provider: $asGrid->provider,
        )];

        return (new ServiceGridProvider(
            new ArrayToDefinitionConverter(new EventDispatcher()),
            new GridRegistry(new ServiceLocator($locatedGrids)),
            new GridConfigurationExtender(),
            new GridConfigurationRemovalsHandler(),
            new GridConfigurationSortingHandler(),
        ))->get($gridName);
    }

    private function prefillingGridFields(Grid $grid): void
    {
        foreach ($grid->getFields() as $field) {
            // Prefilling the default values for datetime fields as they might not be set in YAML configuration
            if ($field->getType() === 'datetime') {
                $options = [
                    'format' => 'Y-m-d H:i:s',
                    'timezone' => null,
                    ...$field->getOptions(),
                ];
                $field->setOptions($options);
            }
        }
    }

    private function buildArrayGridDefinition(string $gridName, array $gridConfig): Grid
    {
        $grid = (new ArrayGridProvider(
            new ArrayToDefinitionConverter(new EventDispatcher()),
            [$gridName => $gridConfig],
            new GridConfigurationExtender(),
            new GridConfigurationRemovalsHandler(),
            new GridConfigurationSortingHandler(),
        ))->get($gridName);

        $this->prefillingGridFields($grid);

        return $grid;
    }

    private function resolveAsGrid(string $class): AsGrid
    {
        $attributes = (new \ReflectionClass($class))->getAttributes(AsGrid::class);

        return ($attributes[0] ?? null)?->newInstance() ?? new AsGrid();
    }
}
