<?php

namespace Tests\Unit\Zatca;

use App\Models\Organization;
use App\Services\Zatca\OpenSslZatcaCsrGenerator;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class OpenSslZatcaCsrGeneratorTest extends TestCase
{
    public function test_it_uses_argument_arrays_secures_temporary_material_and_removes_the_work_directory(): void
    {
        config()->set([
            'zatca.environment' => 'sandbox',
            'zatca.openssl_bin' => 'test-openssl',
        ]);

        $organization = new Organization([
            'name' => "Gaslah\\\n[unsafe]\n\$ENV::HOME #",
            'vat_number' => '300000000000003',
            'cr_number' => '1010000000',
            'address' => 'Riyadh',
        ]);
        $organization->setAttribute('id', 73);

        $directory = null;
        $calls = 0;

        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use (&$calls, &$directory) {
            $calls++;
            $this->assertIsArray($process->command);
            $this->assertSame('test-openssl', $process->command[0]);

            $out = array_search('-out', $process->command, true);
            $this->assertIsInt($out);
            $outputPath = $process->command[$out + 1];
            $directory ??= dirname($outputPath);

            if (in_array('ecparam', $process->command, true)) {
                $configPath = $directory.DIRECTORY_SEPARATOR.'csr-config.cnf';
                $this->assertFileExists($configPath);
                $this->assertStringContainsString('O = Gaslah unsafe ENV::HOME', File::get($configPath));
                $this->assertOwnerOnlyWhenSupported($configPath);
                File::put($outputPath, 'fake-private-key');
            } else {
                $key = array_search('-key', $process->command, true);
                $this->assertIsInt($key);
                $this->assertOwnerOnlyWhenSupported($process->command[$key + 1]);
                File::put($outputPath, 'fake-csr');
            }

            return Process::result();
        });

        $generated = (new OpenSslZatcaCsrGenerator)->generate($organization);

        $this->assertSame(2, $calls);
        $this->assertSame('fake-private-key', $generated['private_key_pem']);
        $this->assertSame('fake-csr', $generated['csr_pem']);
        $this->assertSame('Gaslah unsafe ENV::HOME', $generated['subject']['organization_name']);
        $this->assertNotNull($directory);
        $this->assertDirectoryDoesNotExist($directory);
    }

    private function assertOwnerOnlyWhenSupported(string $path): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0600, fileperms($path) & 0777);
        }
    }
}
