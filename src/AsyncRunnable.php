<?php
declare(strict_types=1);

namespace PocketFlow;

/**
 * Marker interface for nodes that can be run asynchronously.
 *
 * Nodes implementing this interface can be executed within an AsyncFlow
 * and provide a _runAsync() method that returns a Promise.
 */
interface AsyncRunnable {}
