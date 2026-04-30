<?php
declare(strict_types=1);

namespace PocketFlow;

/**
 * A flow that runs its sub-flow for each item returned by prep().
 *
 * The prep() method should return an array of parameter sets. For each
 * set, the sub-flow (starting at startNode) is orchestrated with those
 * parameters merged into the node's params.
 */
class BatchFlow extends Flow
{
    /**
     * Execute the sub-flow once for each parameter set.
     *
     * @param SharedStore $shared The shared data store
     * @return string|null The result from post()
     */
    protected function _run(SharedStore $shared): ?string
    {
        $paramList = $this->prep($shared) ?? [];
        foreach ($paramList as $batchParams) {
            $this->_orchestrate($shared, array_merge($this->params, $batchParams));
        }
        return $this->post($shared, $paramList, null);
    }
}
