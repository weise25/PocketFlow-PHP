<?php
declare(strict_types=1);

namespace PocketFlow;

/**
 * Shared data container passed through a Flow graph.
 *
 * Properties can be added dynamically like a stdClass object,
 * but the class name improves type safety and IDE autocompletion.
 *
 * @property mixed $... All dynamic properties
 */
#[\AllowDynamicProperties]
class SharedStore
{
}
