<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Tests\Integration;

use HighPerApp\Cache\Session\SessionStore;
use HighPerApp\Cache\Session\SessionHandler;
use HighPerApp\Cache\CacheManager;
use HighPerApp\Cache\Configuration\Configuration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SessionIntegrationTest extends TestCase
{
    private SessionStore $sessionStore;
    private SessionHandler $sessionHandler;
    private CacheManager $cacheManager;
    private Configuration $config;
    
    protected function setUp(): void
    {
        // Create configuration
        $this->config = new Configuration([
            'session' => [
                'prefix' => 'test_sess:',
                'lifetime' => 3600,
                'lock_timeout' => 30,
            ],
            'engines' => [
                'pure_php' => [
                    'enabled' => true,
                    'store_type' => 'memory',
                ],
            ],
        ]);
        
        // Create cache manager with memory store
        $this->cacheManager = new CacheManager(
            null, // engine manager will be auto-created
            $this->config,
            new NullLogger()
        );
        
        // Create session store
        $this->sessionStore = new SessionStore(
            $this->cacheManager,
            $this->config,
            new NullLogger()
        );
        
        $this->sessionHandler = $this->sessionStore->getHandler();
    }
    
    protected function tearDown(): void
    {
        // Clean up any active sessions
        $this->sessionHandler->close();
    }
    
    public function testSessionLifecycle(): void
    {
        $sessionId = 'test_session_lifecycle';
        $sessionData = 'user_data_lifecycle';
        
        // Open session
        $this->assertTrue($this->sessionHandler->open('/tmp', 'PHPSESSID'));
        
        // Read non-existent session
        $result = $this->sessionHandler->read($sessionId);
        $this->assertSame('', $result);
        
        // Write session data
        $this->assertTrue($this->sessionHandler->write($sessionId, $sessionData));
        
        // Read session data
        $result = $this->sessionHandler->read($sessionId);
        $this->assertSame($sessionData, $result);
        
        // Update session data
        $updatedData = 'updated_user_data';
        $this->assertTrue($this->sessionHandler->write($sessionId, $updatedData));
        
        // Read updated session data
        $result = $this->sessionHandler->read($sessionId);
        $this->assertSame($updatedData, $result);
        
        // Destroy session
        $this->assertTrue($this->sessionHandler->destroy($sessionId));
        
        // Verify session is destroyed
        $result = $this->sessionHandler->read($sessionId);
        $this->assertSame('', $result);
        
        // Close session
        $this->assertTrue($this->sessionHandler->close());
    }
    
    public function testSessionLocking(): void
    {
        $sessionId = 'test_session_locking';
        
        // Acquire lock
        $this->assertTrue($this->sessionHandler->lock($sessionId));
        
        // Check if locked
        $this->assertTrue($this->sessionHandler->isLocked($sessionId));
        
        // Try to acquire lock again (should fail)
        $this->assertFalse($this->sessionHandler->lock($sessionId, 1));
        
        // Release lock
        $this->assertTrue($this->sessionHandler->unlock($sessionId));
        
        // Check if unlocked
        $this->assertFalse($this->sessionHandler->isLocked($sessionId));
        
        // Should be able to acquire lock again
        $this->assertTrue($this->sessionHandler->lock($sessionId));
        
        // Clean up
        $this->assertTrue($this->sessionHandler->unlock($sessionId));
    }
    
    public function testSessionMetadata(): void
    {
        $sessionId = 'test_session_metadata';
        $sessionData = 'user_data_metadata';
        
        // Write session data
        $this->assertTrue($this->sessionHandler->write($sessionId, $sessionData));
        
        // Get metadata
        $metadata = $this->sessionHandler->getMetadata($sessionId);
        
        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('created_at', $metadata);
        $this->assertArrayHasKey('updated_at', $metadata);
        $this->assertIsInt($metadata['created_at']);
        $this->assertIsInt($metadata['updated_at']);
        
        // Clean up
        $this->assertTrue($this->sessionHandler->destroy($sessionId));
    }
    
    public function testSessionTtl(): void
    {
        $sessionId = 'test_session_ttl';
        $sessionData = 'user_data_ttl';
        
        // Write session data
        $this->assertTrue($this->sessionHandler->write($sessionId, $sessionData));
        
        // Get TTL
        $ttl = $this->sessionHandler->getTtl($sessionId);
        $this->assertIsInt($ttl);
        $this->assertLessThanOrEqual(3600, $ttl);
        $this->assertGreaterThan(0, $ttl);
        
        // Set custom TTL
        $this->assertTrue($this->sessionHandler->setTtl($sessionId, 7200));
        
        // Verify TTL was updated
        $newTtl = $this->sessionHandler->getTtl($sessionId);
        $this->assertIsInt($newTtl);
        $this->assertLessThanOrEqual(7200, $newTtl);
        $this->assertGreaterThan(3600, $newTtl);
        
        // Clean up
        $this->assertTrue($this->sessionHandler->destroy($sessionId));
    }
    
    public function testSessionValidation(): void
    {
        $validSessionId = 'valid_session_id_123';
        $invalidSessionId = 'invalid-session-id!';
        
        // Test valid session ID format
        $this->assertTrue($this->sessionHandler->validateId($validSessionId));
        
        // Test invalid session ID format
        $this->assertFalse($this->sessionHandler->validateId($invalidSessionId));
        
        // Test non-existent session
        $this->assertFalse($this->sessionHandler->validateId('nonexistent_session'));
        
        // Create a session and test validation
        $this->assertTrue($this->sessionHandler->write($validSessionId, 'test_data'));
        $this->assertTrue($this->sessionHandler->validateId($validSessionId));
        
        // Clean up
        $this->assertTrue($this->sessionHandler->destroy($validSessionId));
    }
    
    public function testSessionCreation(): void
    {
        // Test session ID generation
        $sessionId1 = $this->sessionHandler->createSid();
        $sessionId2 = $this->sessionHandler->createSid();
        
        $this->assertIsString($sessionId1);
        $this->assertIsString($sessionId2);
        $this->assertNotEmpty($sessionId1);
        $this->assertNotEmpty($sessionId2);
        $this->assertNotEquals($sessionId1, $sessionId2);
        
        // Test session IDs are valid
        $this->assertTrue($this->sessionHandler->validateId($sessionId1));
        $this->assertTrue($this->sessionHandler->validateId($sessionId2));
    }
    
    public function testSessionStats(): void
    {
        $sessionId = 'test_session_stats';
        $sessionData = 'user_data_stats';
        
        // Get initial stats
        $initialStats = $this->sessionHandler->getStats();
        $this->assertIsArray($initialStats);
        
        // Write session data
        $this->assertTrue($this->sessionHandler->write($sessionId, $sessionData));
        
        // Get stats after write
        $stats = $this->sessionHandler->getStats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('active_locks', $stats);
        $this->assertArrayHasKey('max_lifetime', $stats);
        $this->assertArrayHasKey('lock_timeout', $stats);
        $this->assertArrayHasKey('prefix', $stats);
        
        // Clean up
        $this->assertTrue($this->sessionHandler->destroy($sessionId));
    }
    
    public function testSessionStoreIntegration(): void
    {
        // Test session store wrapper
        $sessionId = 'test_store_integration';
        $sessionData = 'store_data';
        
        // Test direct store methods
        $this->assertTrue($this->sessionStore->open('/tmp', 'PHPSESSID'));
        $this->assertSame('', $this->sessionStore->read($sessionId));
        $this->assertTrue($this->sessionStore->write($sessionId, $sessionData));
        $this->assertSame($sessionData, $this->sessionStore->read($sessionId));
        $this->assertTrue($this->sessionStore->destroy($sessionId));
        $this->assertTrue($this->sessionStore->close());
        
        // Test store-specific methods
        $stats = $this->sessionStore->getStats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('store_type', $stats);
        $this->assertSame('cache_based', $stats['store_type']);
        
        // Test session creation
        $newSessionId = $this->sessionStore->createSid();
        $this->assertIsString($newSessionId);
        $this->assertNotEmpty($newSessionId);
    }
    
    public function testSessionGarbageCollection(): void
    {
        $sessionId = 'test_gc_session';
        $sessionData = 'gc_test_data';
        
        // Write session data
        $this->assertTrue($this->sessionHandler->write($sessionId, $sessionData));
        
        // Run garbage collection
        $collected = $this->sessionHandler->gc(3600);
        $this->assertIsInt($collected);
        $this->assertGreaterThanOrEqual(0, $collected);
        
        // Clean up
        $this->assertTrue($this->sessionHandler->destroy($sessionId));
    }
    
    public function testSessionTimestampUpdate(): void
    {
        $sessionId = 'test_timestamp_update';
        $sessionData = 'timestamp_data';
        
        // Write session data
        $this->assertTrue($this->sessionHandler->write($sessionId, $sessionData));
        
        // Update timestamp
        $this->assertTrue($this->sessionHandler->updateTimestamp($sessionId, $sessionData));
        
        // Touch session
        $this->assertTrue($this->sessionHandler->touch($sessionId));
        
        // Clean up
        $this->assertTrue($this->sessionHandler->destroy($sessionId));
    }
}