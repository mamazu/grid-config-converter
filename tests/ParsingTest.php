<?php

use Mamazu\ConfigConverter\ClassConfigConverter;
use Sylius\Bundle\GridBundle\Builder\GridBuilder;
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
        $gridBuilder = GridBuilder::create($gridName);
        $grid->__invoke($gridBuilder);
        $gridConfig = $gridBuilder->toArray();
        unset($gridConfig['removals']);

        $this->assertEquals($yaml, $gridConfig);
    }
}
