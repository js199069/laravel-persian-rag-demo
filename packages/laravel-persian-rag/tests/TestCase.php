<?php

namespace Js199069\PersianRag\Tests;

use Js199069\PersianRag\PersianRagServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PersianRagServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('RAG_TEST_DB_HOST', '127.0.0.1'),
            'port' => env('RAG_TEST_DB_PORT', '5432'),
            'database' => env('RAG_TEST_DB_DATABASE', 'persian_rag_test'),
            'username' => env('RAG_TEST_DB_USERNAME', 'rag'),
            'password' => env('RAG_TEST_DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }
}
