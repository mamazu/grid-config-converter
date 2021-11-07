<?php

namespace Mamazu\ConfigConverter;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter\Standard;

class PrettyCodeStandard extends Standard
{
    protected function pExpr_MethodCall(Expr\MethodCall $node): string
    {
        if($node->var instanceof Expr\Variable) {
            $this->indent();
        }

        return $this->pDereferenceLhs($node->var) . $this->nl."->" . $this->pObjectProperty($node->name)
            . '(' . $this->pMaybeMultiline($node->args) . ')';
    }

    protected function pExpr_Array(Expr\Array_ $node) {
        return '[' . $this->pCommaSeparatedMultiline($node->items, true) .$this->nl. ']';
    }

    protected function pStmt_Expression(Stmt\Expression $node) {
        $printedExpression= $this->p($node->expr);

        if(strpos($printedExpression, "\n")) {
            $this->outdent();
            $printedExpression.= $this->nl;
        }
        $printedExpression.=';';

        return $printedExpression;
    }

}