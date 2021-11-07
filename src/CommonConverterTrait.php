<?php

namespace Mamazu\ConfigConverter;

use InvalidArgumentException;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;

trait CommonConverterTrait
{
    private function checkUnconsumedConfiguration(string $key, array $configuration) {
        if (count($configuration) !== 0) {
            throw new InvalidArgumentException(
                sprintf('There are unconsumed fields under the key "%s": %s',
                    $key,
                    print_r($configuration, true)
                )
            );
        }
    }

    private function convertToFunctionCall(Expr &$field, array &$configuration, string $fieldName): void
    {
        if (!array_key_exists($fieldName, $configuration)) {
            return;
        }

        // converting form_options to setFormOptions
        $methodName = 'set'.ucfirst(preg_replace_callback('#_\w#', static fn($a) => strtoupper($a[0][1]), $fieldName));
        $field = new MethodCall($field, $methodName, [$this->convertValue($configuration[$fieldName])]);
        unset($configuration[$fieldName]);
    }
}