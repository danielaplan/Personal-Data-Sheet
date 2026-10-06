<?php

declare(strict_types=1);

namespace Pds\Tests\Support;

use RuntimeException;

final class HttpServer
{
    private mixed $process = null;
    private string $temporaryDirectory;
    private string $baseUrl;

    public function __construct(array $environment)
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        if ($listener === false) {
            throw new RuntimeException('Unable to reserve a test HTTP port.');
        }
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->baseUrl = 'http://' . $address;
        $this->temporaryDirectory = sys_get_temp_dir() . '/pds-http-test-' . bin2hex(random_bytes(12));
        if (!mkdir($this->temporaryDirectory, 0700)) {
            throw new RuntimeException('Unable to create a test session directory.');
        }
        $log = $this->temporaryDirectory . '/server.log';
        $this->process = proc_open(
            [PHP_BINARY, '-d', 'session.save_path=' . $this->temporaryDirectory, '-d', 'display_errors=0', '-S', $address, '-t', dirname(__DIR__, 2)],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            dirname(__DIR__, 2),
            $environment,
            ['bypass_shell' => true, 'create_no_window' => true],
        );
        if (!is_resource($this->process)) {
            $this->stop();
            throw new RuntimeException('Unable to start the test HTTP server.');
        }
        fclose($pipes[0]);
        $deadline = microtime(true) + 5;
        do {
            $connection = @stream_socket_client('tcp://' . $address, $error, $message, 0.1);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline && proc_get_status($this->process)['running']);
        $this->stop();
        throw new RuntimeException('The test HTTP server did not become ready.');
    }

    public function client(): HttpClient
    {
        return new HttpClient($this->baseUrl);
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        if (isset($this->temporaryDirectory) && is_dir($this->temporaryDirectory)) {
            foreach (glob($this->temporaryDirectory . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->temporaryDirectory);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
