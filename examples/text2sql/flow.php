<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/nodes.php';

use PocketFlow\Flow;

function createFullFlow(): Flow
{
    $getSchema = new GetSchema();
    $generateSql = new GenerateSQL();
    $executeSql = new ExecuteSQL();
    $debugSql = new DebugSQL();

    $getSchema->next($generateSql);
    $generateSql->next($executeSql);
    $executeSql->next($debugSql, 'error_retry');
    $debugSql->next($executeSql);

    return new Flow($getSchema);
}

function createQueryFlow(): Flow
{
    $generateSql = new GenerateSQL();
    $executeSql = new ExecuteSQL();
    $debugSql = new DebugSQL();

    $generateSql->next($executeSql);
    $executeSql->next($debugSql, 'error_retry');
    $debugSql->next($executeSql);

    return new Flow($generateSql);
}
