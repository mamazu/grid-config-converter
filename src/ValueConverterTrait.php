<?php

namespace Mamazu\ConfigConverter;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use InvalidArgumentException;

trait ValueConverterTrait
{
    public function convertValue($value): Expr
    {
        if (is_string($value)) {
            return new String_($value);
        }

        if (is_bool($value)) {
            return new ConstFetch(new Name($value ? 'true' : 'false'));
        }

        if ($value === null) {
            return new ConstFetch(new Name('null'));
        }
        if (is_int($value)) {
            return new ConstFetch(new Name((string)$value));
        }

        if (is_array($value)) {
            $items = [];
            foreach ($value as $key => $subValue) {
                $val = $this->convertValue($subValue);
                $convertedKey = null;
                if (is_string($key)) {
                    $convertedKey = new String_($key);
                }
                $items[] = new Node\Expr\ArrayItem($val, $convertedKey);
            }

            return new Array_($items, ['kind' => Array_::KIND_SHORT]);
        }
        throw new InvalidArgumentException('Could not convert datatype: '.get_debug_type($value));
    }
}