<?php

namespace Mamazu\ConfigConverter;

use PhpParser\PrettyPrinter\Standard;

class CodeOutputter
{
    private Standard $prettyPrinter;

    public function __construct()
    {
        $this->prettyPrinter = new PrettyCodeStandard();
    }

    public function printCode(array $code): string
    {
        $code = $this->prettyPrinter->prettyPrint($code);

        return <<<CODE
<?php
/**
 * This code is generated by the config converter under https://github.com/mamazu/grid-config-converter
 * Feel free to modify the code as you see fit.
 */
$code

CODE;
    }
}