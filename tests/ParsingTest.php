<?php

use Mamazu\ConfigConverter\ClassConfigConverter;
use Symfony\Component\Yaml\Yaml;

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
                $builder->convert($file->getFilename());
            }
        }
    }

    public function testConfigurationForOrder() {
        $yaml = Yaml::parse(file_get_contents('order.yml'))['sylius_grid']['grids']['sylius_admin_order'];

        $this->compileFile();
        include 'SyliusAdminOrder.php';
        $orderGrid = new \SyliusAdminOrder();

        $this->assertEquals($yaml, $orderGrid->toArray());
    }

    public function testConfigurationForAdvancedConfig() {
        $yaml = Yaml::parse(file_get_contents('advanced_configuration.yml'))['sylius_grid']['grids']['foo'];

        $this->compileFile();
        include 'Foo.php';
        $orderGrid = new \Foo();

        $this->assertEquals($yaml, $orderGrid->toArray());
    }
}
