<?php

namespace Tests\Unit;

use App\Services\Install\EnvironmentWriter;
use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;

class EnvironmentWriterTest extends TestCase
{
    public function test_database_password_round_trips_through_dotenv(): void
    {
        $directory = sys_get_temp_dir().'/td-env-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $file = $directory.'/.env';

        try {
            $password = 'a$B"c\\d#e + (f)';
            (new EnvironmentWriter)->write($file, ['DB_PASSWORD' => $password, 'APP_EDITION' => 'standalone']);

            $this->assertSame($password, Dotenv::parse((string) file_get_contents($file))['DB_PASSWORD']);
            $this->assertSame('standalone', Dotenv::parse((string) file_get_contents($file))['APP_EDITION']);
            $this->assertSame(0600, fileperms($file) & 0777);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
