<?php

declare(strict_types=1);

namespace Rector\PostRector\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\NodeTraverser;
use Rector\Core\PhpParser\Node\NodeFactory;
use Rector\Core\RectorDefinition\RectorDefinition;
use Rector\PostRector\Collector\NodesToRemoveCollector;

final class NodeRemovingRector extends AbstractPostRector
{
    /**
     * @var NodesToRemoveCollector
     */
    private $nodesToRemoveCollector;

    /**
     * @var NodeFactory
     */
    private $nodeFactory;

    public function __construct(NodesToRemoveCollector $nodesToRemoveCollector, NodeFactory $nodeFactory)
    {
        $this->nodesToRemoveCollector = $nodesToRemoveCollector;
        $this->nodeFactory = $nodeFactory;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('PostRector that removes nodes');
    }

    public function getPriority(): int
    {
        return 800;
    }

    public function enterNode(Node $node): ?Node
    {
        if (! $this->nodesToRemoveCollector->isActive()) {
            return null;
        }

        // special case for fluent methods
        foreach ($this->nodesToRemoveCollector->getNodesToRemove() as $key => $nodeToRemove) {
            // replace chain method call by non-chain method call
            if (! $this->isChainMethodCallNodeToBeRemoved($node, $nodeToRemove)) {
                continue;
            }

            $this->nodesToRemoveCollector->unset($key);

            /** @var MethodCall $node */
            $methodName = $this->getName($node->name);

            /** @var MethodCall $nestedMethodCall */
            $nestedMethodCall = $node->var;

            /** @var string $methodName */
            return $this->nodeFactory->createMethodCall($nestedMethodCall->var, $methodName, $node->args);
        }

        return null;
    }

    /**
     * @return int|Node|Node[]|null
     */
    public function leaveNode(Node $node)
    {
        foreach ($this->nodesToRemoveCollector->getNodesToRemove() as $key => $nodeToRemove) {
            if ($node === $nodeToRemove) {
                $this->nodesToRemoveCollector->unset($key);

                return NodeTraverser::REMOVE_NODE;
            }
        }

        return $node;
    }

    private function isChainMethodCallNodeToBeRemoved(Node $node, Node $nodeToRemove): bool
    {
        if (! $nodeToRemove instanceof MethodCall) {
            return false;
        }

        if (! $node instanceof MethodCall || ! $node->var instanceof MethodCall) {
            return false;
        }

        if ($nodeToRemove !== $node->var) {
            return false;
        }

        $methodName = $this->getName($node->name);

        return $methodName !== null;
    }
}
