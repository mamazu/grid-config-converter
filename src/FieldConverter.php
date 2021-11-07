<?php

namespace Mamazu\ConfigConverter;

use PhpParser\Comment;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;

class FieldConverter
{
    use CommonConverterTrait;
    use ValueConverterTrait;

    public function convertField(Expr $gridBuilder, string $fieldName, array $fieldConfig): Expr
    {
        $field = $this->createField($fieldConfig, $fieldName);
        unset($fieldConfig['type']);

        $this->convertToFunctionCall($field, $fieldConfig, 'enabled');
        $this->convertToFunctionCall($field, $fieldConfig, 'label');
        $this->convertToFunctionCall($field, $fieldConfig, 'position');
        $this->convertToFunctionCall($field, $fieldConfig, 'path');

        /*
         * Handling of the sortable attribute is a little complicated because:
         * sortable: ~
         * means the field is sortable with the default configuration
         */
        if (array_key_exists('sortable', $fieldConfig)) {
            $path = $fieldConfig['sortable'];

            $arguments = [
                new ConstFetch(new Name('true')),
            ];
            if ($path !== null) {
                $arguments[] = new String_($path);
            }
            $field = new MethodCall($field, 'setSortable', $arguments);
            unset($fieldConfig['sortable']);
        }

        // Only add the options if the value is not empty. This can happen for the twig field for example. The template is now
        // part of the create call and not an option anymore
        if (isset($fieldConfig['options'])) {
            if (count($fieldConfig['options']) > 0) {
                $field = new MethodCall($field, 'setOptions', [$this->convertValue($fieldConfig['options'])]);
            }
            unset($fieldConfig['options']);
        }

        $this->checkUnconsumedConfiguration('fields', $fieldConfig);

        return new MethodCall($gridBuilder, 'addField', [new Arg($field)]);
    }

    private function createField(array $fieldConfig, string $fieldName): Expr
    {
        switch ($fieldConfig['type']) {
            case 'datetime':
                $field = new StaticCall(new Name('DateTimeField'), 'create', [
                    $this->convertValue($fieldName),
                ]);
                break;
            case 'string':
                $field = new StaticCall(new Name('StringField'), 'create', [
                    $this->convertValue($fieldName),
                ]);
                break;
            case 'twig':
                $field = new StaticCall(new Name('TwigField'), 'create', [
                    $this->convertValue($fieldName),
                    $this->convertValue($fieldConfig['options']['template']),
                ]);
                break;
            default:
                $field = new StaticCall(new Name('Field'), 'create', [
                    $this->convertValue($fieldName),
                    $this->convertValue($fieldConfig['type']),
                ]);
        }
        return $field;
    }
}