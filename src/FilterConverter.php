<?php

namespace Mamazu\ConfigConverter;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;

class FilterConverter
{
    use CommonConverterTrait;
    use ValueConverterTrait;

    public function convertFilter(Expr $gridBuilder, string $filterName, array $configuration): Expr
    {
        $filter = new StaticCall(new Name('Filter'), 'create', [
            $this->convertValue($filterName),
            $this->convertValue($configuration['type']),
        ]);
        unset($configuration['type']);

        $this->convertToFunctionCall($filter, $configuration, 'enabled');
        $this->convertToFunctionCall($filter, $configuration, 'label');
        $this->convertToFunctionCall($filter, $configuration, 'options');
        $this->convertToFunctionCall($filter, $configuration, 'form_options');

        $this->checkUnconsumedConfiguration('filter', $configuration);

        return new MethodCall($gridBuilder, 'addFilter', [new Arg($filter)]);
    }
}