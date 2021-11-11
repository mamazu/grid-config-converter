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

        // Prints $var->methodName
        $methodCall = $this->pDereferenceLhs($node->var) . $this->nl."->" . $this->pObjectProperty($node->name);

        // If the first argument is a static call then we want to print it in multiple lines
        $firstArgument = $node->args[0]->value ?? null;
        if($this->unwrapChainedMethodCall($firstArgument) instanceof Expr\StaticCall) {
            $arguments =  '(' . $this->pCommaSeparatedMultiline($node->args, false) . $this->nl . ')';
        } else {
            $arguments =  '(' . $this->pMaybeMultiline($node->args) . ')';
        }

        return $methodCall . $arguments;
    }

    private function unwrapChainedMethodCall($methodCall) {
        if ($methodCall === null || $methodCall instanceof Expr\StaticCall) {
            return $methodCall;
        }

        $methodCallRef = $methodCall;
        while($methodCallRef instanceof Expr\MethodCall) {
            $methodCallRef = $methodCallRef->var;
        }
        return $methodCallRef;
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
