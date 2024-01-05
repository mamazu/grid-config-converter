<?php

use Mamazu\ConfigConverter\ClassConfigConverter;
use Sylius\Component\Grid\Definition\ArrayToDefinitionConverter;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ParsingTest extends \PHPUnit\Framework\TestCase
{
    public function setup(): void {
        chdir(__DIR__);
    }

    private function compileFile() {
        $builder = new ClassConfigConverter();
        $builder->makeQuiet();
        foreach(new DirectoryIterator('.') as $file) {
            /** @var DirectoryIterator $file */
            if(in_array($file->getExtension(), ['yaml', 'yml'])) {
                $builder->convert($file->getFilename(), __DIR__);
            }
        }
    }

    /** @covers  */
    public function testConfigurationForOrder()
    {
        $yaml = Yaml::parse(file_get_contents('order.yml'))['sylius_grid']['grids']['sylius_admin_order'];

        $this->compileFile();
        include 'SyliusAdminOrder.php';
        $orderGrid = new \SyliusAdminOrder();

        $edi = $this->createMock(EventDispatcherInterface::class);
        $converter = new ArrayToDefinitionConverter($edi);

        $def1 = $converter->convert('sylius_admin_order', $yaml);
        $def2 = $converter->convert('sylius_admin_order', $orderGrid->toArray());

        $this->assertEquals($def1, $def2);
    }

    /** @covers  */
    public function testConfigurationForAdvancedConfig()
    {
        $yaml = Yaml::parse(file_get_contents('advanced_configuration.yml'))['sylius_grid']['grids']['foo'];

        $this->compileFile();
        include 'Foo.php';
        $orderGrid = new \Foo();

        $edi = $this->createMock(EventDispatcherInterface::class);
        $converter = new ArrayToDefinitionConverter($edi);

        $def1 = $converter->convert('foo', $yaml);
        $def2 = $converter->convert('foo', $orderGrid->toArray());

        $this->assertEquals($def1, $def2);
    }
}
