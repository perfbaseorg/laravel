<?php

namespace Tests;

use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;
use Perfbase\Laravel\Caching\FileStrategy;
use Perfbase\Laravel\PerfbaseServiceProvider;
use InvalidArgumentException;

class FileStrategyTest extends TestCase
{
    private FileStrategy $strategy;
    private string $testPath;

    protected function getPackageProviders($app): array
    {
        return [PerfbaseServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->testPath = storage_path('testing/perfbase');
        
        // Set up file configuration
        config([
            'perfbase.sending.config.file.path' => $this->testPath
        ]);
        
        // Clean up and create test directory
        if (File::exists($this->testPath)) {
            File::deleteDirectory($this->testPath);
        }
        File::makeDirectory($this->testPath, 0755, true);
        
        $this->strategy = new FileStrategy();
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        if (File::exists($this->testPath)) {
            File::deleteDirectory($this->testPath);
        }
        
        parent::tearDown();
    }

    public function testConstructorThrowsExceptionWithInvalidPath()
    {
        config(['perfbase.sending.config.file.path' => null]);
        
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The file cache path must be a string');
        
        new FileStrategy();
    }

    public function testStore()
    {
        $profileData = ['trace' => 'test_data', 'timestamp' => time()];
        
        $this->strategy->store($profileData);
        
        $files = File::files($this->testPath);
        $this->assertCount(1, $files);
        
        $file = $files[0];
        $this->assertStringEndsWith('.perfbase', $file->getFilename());
        
        $content = unserialize(File::get($file));
        $this->assertArrayHasKey('id', $content);
        $this->assertArrayHasKey('data', $content);
        $this->assertArrayHasKey('created_at', $content);
        $this->assertEquals($profileData, $content['data']);
    }

    public function testStoreCreatesDirectoryIfNotExists()
    {
        // Delete the directory
        File::deleteDirectory($this->testPath);
        $this->assertFalse(File::exists($this->testPath));
        
        $profileData = ['trace' => 'test_data'];
        $this->strategy->store($profileData);
        
        $this->assertTrue(File::exists($this->testPath));
        $this->assertCount(1, File::files($this->testPath));
    }

    public function testGetUnsentProfiles()
    {
        // Create test profiles
        $profiles = [];
        for ($i = 0; $i < 5; $i++) {
            $data = ['trace' => "test_data_$i", 'index' => $i];
            $this->strategy->store($data);
            $profiles[] = $data;
        }
        
        // Get all profiles
        $retrievedProfiles = [];
        foreach ($this->strategy->getUnsentProfiles() as $chunk) {
            foreach ($chunk as $profile) {
                $retrievedProfiles[] = $profile;
            }
        }
        
        $this->assertCount(5, $retrievedProfiles);
        
        // Verify structure
        foreach ($retrievedProfiles as $profile) {
            $this->assertArrayHasKey('id', $profile);
            $this->assertArrayHasKey('data', $profile);
            $this->assertArrayHasKey('created_at', $profile);
        }
    }

    public function testGetUnsentProfilesChunking()
    {
        // Create 10 profiles
        for ($i = 0; $i < 10; $i++) {
            $this->strategy->store(['index' => $i]);
        }
        
        // Get profiles in chunks of 3
        $chunks = [];
        foreach ($this->strategy->getUnsentProfiles(3) as $chunk) {
            $chunks[] = $chunk;
        }
        
        // Should have 4 chunks: 3, 3, 3, 1
        $this->assertCount(4, $chunks);
        $this->assertCount(3, $chunks[0]);
        $this->assertCount(3, $chunks[1]);
        $this->assertCount(3, $chunks[2]);
        $this->assertCount(1, $chunks[3]);
    }

    public function testCountUnsentProfiles()
    {
        $this->assertEquals(0, $this->strategy->countUnsentProfiles());
        
        $this->strategy->store(['test' => 'data1']);
        $this->strategy->store(['test' => 'data2']);
        $this->strategy->store(['test' => 'data3']);
        
        $this->assertEquals(3, $this->strategy->countUnsentProfiles());
    }

    public function testDelete()
    {
        $this->strategy->store(['test' => 'data']);
        $files = File::files($this->testPath);
        $this->assertCount(1, $files);
        
        $filename = $files[0]->getFilename();
        $this->strategy->delete($filename);
        
        $this->assertCount(0, File::files($this->testPath));
    }

    public function testDeleteNonExistentFile()
    {
        // Should not throw exception
        $this->strategy->delete('non-existent-file.perfbase');
        $this->assertTrue(true); // Assert no exception was thrown
    }

    public function testDeleteMass()
    {
        // Create 5 files
        $filenames = [];
        for ($i = 0; $i < 5; $i++) {
            $this->strategy->store(['index' => $i]);
        }
        
        $files = File::files($this->testPath);
        foreach ($files as $file) {
            $filenames[] = $file->getFilename();
        }
        
        $this->assertCount(5, $files);
        
        // Delete first 3
        $this->strategy->deleteMass(array_slice($filenames, 0, 3));
        
        $this->assertCount(2, File::files($this->testPath));
    }

    public function testClear()
    {
        // Create multiple profiles
        for ($i = 0; $i < 5; $i++) {
            $this->strategy->store(['index' => $i]);
        }
        
        // Create a non-perfbase file that should not be deleted
        File::put($this->testPath . '/other-file.txt', 'content');
        
        $this->assertCount(6, File::files($this->testPath)); // 5 .perfbase + 1 .txt
        
        $this->strategy->clear();
        
        $files = File::files($this->testPath);
        $this->assertCount(1, $files); // Only the .txt file should remain
        $this->assertEquals('other-file.txt', $files[0]->getFilename());
    }

    public function testEmptyGetUnsentProfiles()
    {
        $profiles = [];
        foreach ($this->strategy->getUnsentProfiles() as $chunk) {
            $profiles = array_merge($profiles, $chunk);
        }
        
        $this->assertEmpty($profiles);
    }
}