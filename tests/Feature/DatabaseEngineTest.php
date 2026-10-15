<?php

namespace Tests\Feature;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

/**
 * Tables are created as InnoDB on every MySQL and MariaDB server, never as the
 * server default: a host defaulting to MyISAM (Namecheap's MariaDB) stopped the
 * first standalone install at the patients name index — and MyISAM would have
 * meant no transactions or foreign keys under the books.
 */
class DatabaseEngineTest extends TestCase
{
    public function test_mysql_and_mariadb_connections_create_innodb_tables(): void
    {
        $this->assertSame('InnoDB', config('database.connections.mysql.engine'));
        $this->assertSame('InnoDB', config('database.connections.mariadb.engine'));

        // Compiled without a server: the statement itself must name the engine.
        $connection = new MySqlConnection(
            fn () => throw new \LogicException('No server is needed to compile a statement.'),
            'td', '', config('database.connections.mysql'),
        );
        $connection->useDefaultSchemaGrammar();
        $blueprint = new Blueprint($connection, 'probe', function (Blueprint $table): void {
            $table->create();
            $table->id();
        });

        $this->assertStringContainsString('engine = InnoDB', implode(';', $blueprint->toSql()));
    }

    public function test_the_installer_refuses_a_server_without_innodb(): void
    {
        $installer = (string) file_get_contents(public_path('install.php'));

        $this->assertStringContainsString("WHERE ENGINE = 'InnoDB'", $installer);
        $this->assertStringContainsString('InnoDB storage engine switched off', $installer);
    }
}
