<?php
declare(strict_types=1);

namespace PocketFlow;

/**
 * Helper class to enable the ->on('action')->next($node) syntax.
 *
 * This class is returned by BaseNode::on() and provides a fluent interface
 * for defining conditional transitions to other nodes.
 */
class ConditionalTransition
{
    public function __construct(private BaseNode $source, private string $action) {}

    /**
     * Defines the target node for this transition.
     *
     * @param BaseNode|null $target The node to transition to, or null to remove
     * @return BaseNode|null The target node that was set
     */
    public function next(?BaseNode $target): ?BaseNode
    {
        return $this->source->next($target, $this->action);
    }
}
