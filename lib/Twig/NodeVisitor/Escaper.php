<?php

/*
 * This file is part of Twig.
 *
 * (c) 2009 Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Twig_NodeVisitor_Escaper implements output escaping.
 *
 * @package    twig
 * @author     Fabien Potencier <fabien.potencier@symfony-project.com>
 * @version    SVN: $Id$
 */
class Twig_NodeVisitor_Escaper implements Twig_NodeVisitorInterface
{
    protected $statusStack = array();
    protected $blocks = array();

    protected $safeAnalysis;
    protected $traverser;

    function __construct()
    {
        $this->safeAnalysis = new Twig_NodeVisitor_SafeAnalysis();
    }

    /**
     * Called before child nodes are visited.
     *
     * @param Twig_NodeInterface $node The node to visit
     * @param Twig_Environment   $env  The Twig environment instance
     *
     * @param Twig_NodeInterface The modified node
     */
    public function enterNode(Twig_NodeInterface $node, Twig_Environment $env)
    {
        if ($node instanceof Twig_Node_AutoEscape) {
            $this->statusStack[] = $node->getAttribute('value');
        } elseif ($node instanceof Twig_Node_Print) {
            return $this->escapeNode($node, $env, $this->needEscaping($env));
        } elseif ($node instanceof Twig_Node_Block) {
            $this->statusStack[] = isset($this->blocks[$node->getAttribute('name')]) ? $this->blocks[$node->getAttribute('name')] : $this->needEscaping($env);
        }

        return $node;
    }

    /**
     * Called after child nodes are visited.
     *
     * @param Twig_NodeInterface $node The node to visit
     * @param Twig_Environment   $env  The Twig environment instance
     *
     * @param Twig_NodeInterface The modified node
     */
    public function leaveNode(Twig_NodeInterface $node, Twig_Environment $env)
    {
        if ($node instanceof Twig_Node_AutoEscape || $node instanceof Twig_Node_Block) {
            array_pop($this->statusStack);
        } elseif ($node instanceof Twig_Node_BlockReference) {
            $this->blocks[$node->getAttribute('name')] = $this->needEscaping($env);
        }

        return $node;
    }

    protected function escapeNode(Twig_NodeInterface $node, Twig_Environment $env, $type)
    {
        if (false === $type) {
            return $node;
        }

        $expression = $node instanceof Twig_Node_Print ? $node->getNode('expr') : $node;

        $safe = $this->safeAnalysis->getSafe($expression);

        if (null === $safe) {
            if (null === $this->traverser) {
                $this->traverser = new Twig_NodeTraverser($env, array($this->safeAnalysis));
            }
            $this->traverser->traverse($expression);
            $safe = $this->safeAnalysis->getSafe($expression);
        }

        if (false !== in_array($type, $safe) || false !== in_array('all', $safe)) {
            return $node;
        }

        // escape
        if ($expression instanceof Twig_Node_Expression_Filter) {
            $filter = $this->getEscaperFilter($type, $expression->getLine());
            $expression->appendFilter($filter[0], $filter[1]);

            return $node;
        }

        if ($node instanceof Twig_Node_Print) {
            return new Twig_Node_Print(
                new Twig_Node_Expression_Filter($expression, new Twig_Node($this->getEscaperFilter($type, $node->getLine())), $node->getLine()),
                $node->getLine()
            );
        }

        return new Twig_Node_Expression_Filter($node, new Twig_Node($this->getEscaperFilter($type, $node->getLine())), $node->getLine());
    }

    protected function needEscaping(Twig_Environment $env)
    {
        if (count($this->statusStack)) {
            return $this->statusStack[count($this->statusStack) - 1];
        }

        if ($env->hasExtension('escaper') && $env->getExtension('escaper')->isGlobal()) {
            return 'html';
        }

        return false;
    }

    protected function getEscaperFilter($type, $line)
    {
        return array(new Twig_Node_Expression_Constant('escape', $line), new Twig_Node(array(new Twig_Node_Expression_Constant((string) $type, $line))));
    }
}
