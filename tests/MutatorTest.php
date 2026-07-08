<?php

use Mamazu\ConfigConverter\ClassConfigConverter;
use Sylius\Bundle\GridBundle\Builder\GridBuilder;
use Sylius\Component\Grid\Attribute\AsGridMutator;
use Sylius\Component\Grid\Mutator\GridMutatorInterface;

class MutatorTest extends \PHPUnit\Framework\TestCase
{
    public static function getOutputFolder(): string {
        return __DIR__.'/output_mutator';
    }

    public function tearDown(): void
    {
        exec('rm -rf "'.self::getOutputFolder().'"');
    }

    public function testGeneratesMutatorClass(): void
    {
        $outputDirectory = self::getOutputFolder();
        mkdir($outputDirectory);

        $converter = new ClassConfigConverter();
        $converter->makeQuiet();
        $converter->setMutator();

        $converter->convert(__DIR__.'/fixtures/mutator_grid.yaml', $outputDirectory);

        $this->assertFileExists($outputDirectory.'/SyliusAdminProductGridMutator.php');

        include $outputDirectory.'/SyliusAdminProductGridMutator.php';

        $this->assertTrue(class_exists('SyliusAdminProductGridMutator'));

        $mutator = new SyliusAdminProductGridMutator();
        $this->assertInstanceOf(GridMutatorInterface::class, $mutator);

        $gridBuilder = GridBuilder::create('test', 'App\Entity\Product');
        $mutator($gridBuilder);

        $this->assertSame(
            ['fields' => ['image'], 'filters' => ['date']],
            $gridBuilder->toArray()['removals']
        );
    }
}
