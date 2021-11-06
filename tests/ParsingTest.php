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
        $builder->convert('order.yml');
    }

    public function testConfiguration() {
        $yaml = Yaml::parse(file_get_contents('order.yml'))['sylius_grid']['grids']['sylius_admin_order'];

        $this->compileFile();
        include 'SyliusAdminOrder.php';
        $orderGrid = new \SyliusAdminOrder();

        $this->assertEquals($yaml, $orderGrid->toArray());
    }
}
