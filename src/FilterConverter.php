<?php

namespace Mamazu\ConfigConverter;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use Sylius\Bundle\GridBundle\Builder\Filter\Filter;

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
        if (\array_key_exists('default_value', $configuration)) {
            if (method_exists(Filter::class, 'setDefaultValue') === false) {
                trigger_error(sprintf(
                    'The "%s" class dont have the "%s" method. Please use GridBundle 1.13+. This option is lost in convertion.',
                    Filter::class,
                    'setDefaultValue'
                ));
            } else {
                $filter = new MethodCall(
                    $filter,
                    'setDefaultValue',
                    [$this->convertValue($configuration['default_value'])]
                );
            }
            unset($configuration['default_value']);
        }
        $this->checkUnconsumedConfiguration('filter', $configuration);

        return new MethodCall($gridBuilder, 'addFilter', [new Arg($filter)]);
    }
}
