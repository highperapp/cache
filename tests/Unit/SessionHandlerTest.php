<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Tests\Unit;

use HighPerApp\Cache\Session\SessionHandler;
use HighPerApp\Cache\Session\SessionStore;
use HighPerApp\Cache\Contracts\CacheInterface;
use HighPerApp\Cache\Contracts\ConfigurationInterface;
use HighPerApp\Cache\Configuration\Configuration;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class SessionHandlerTest extends TestCase
{
    private SessionHandler $sessionHandler;
    private MockObject $cache;
    private MockObject $config;
    private LoggerInterface $logger;
    
    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheInterface::class);
        $this->config = $this->createMock(ConfigurationInterface::class);
        $this->logger = new NullLogger();
        
        // Set up default configuration
        $this->config->method('get')
            ->willReturnCallback(function ($key, $default = null) {
                return match ($key) {
                    'session.prefix' => 'sess:',
                    'session.lifetime' => 3600,
                    'session.lock_timeout' => 30,
                    default => $default,
                };
            });
        
        $this->sessionHandler = new SessionHandler(
            $this->cache,
            $this->config,
            $this->logger
        );
    }
    
    public function testOpen(): void
    {
        $result = $this->sessionHandler->open('/tmp', 'PHPSESSID');
        $this->assertTrue($result);
    }
    
    public function testClose(): void
    {
        $result = $this->sessionHandler->close();
        $this->assertTrue($result);
    }
    
    public function testReadEmptySession(): void
    {
        $sessionId = 'test_session_id';
        
        $this->cache->expects($this->once())
            ->method('add')
            ->with('sess:lock:' . $sessionId, $this->anything(), 30)
            ->willReturn(true);
        
        $this->cache->expects($this->once())
            ->method('get')
            ->with('sess:' . $sessionId)
            ->willReturn(null);
        
        $result = $this->sessionHandler->read($sessionId);
        $this->assertSame('', $result);
    }
    
    public function testReadExistingSession(): void
    {
        $sessionId = 'test_session_id';
        $sessionData = ['data' => 'user_data', 'created_at' => time()];
        
        $this->cache->expects($this->once())
            ->method('add')
            ->with('sess:lock:' . $sessionId, $this->anything(), 30)
            ->willReturn(true);
        
        $this->cache->expects($this->once())
            ->method('get')
            ->with('sess:' . $sessionId)
            ->willReturn($sessionData);
        
        $result = $this->sessionHandler->read($sessionId);
        $this->assertSame('user_data', $result);
    }
    
    public function testReadLockFailure(): void
    {
        $sessionId = 'test_session_id';
        
        $this->cache->expects($this->atLeast(1))
            ->method('add')
            ->with('sess:lock:' . $sessionId, $this->anything(), 30)
            ->willReturn(false);
        
        $result = $this->sessionHandler->read($sessionId);
        $this->assertSame('', $result);
    }
    
    public function testWrite(): void
    {
        $sessionId = 'test_session_id';
        $sessionData = 'user_data';
        
        $this->cache->expects($this->once())
            ->method('set')
            ->with(
                'sess:' . $sessionId,
                $this->callback(function ($data) use ($sessionData) {
                    return is_array($data) && 
                           $data['data'] === $sessionData &&
                           isset($data['created_at']) &&
                           isset($data['updated_at']);
                }),
                3600
            )
            ->willReturn(true);
        
        $result = $this->sessionHandler->write($sessionId, $sessionData);
        $this->assertTrue($result);
    }
    
    public function testWriteFailure(): void
    {
        $sessionId = 'test_session_id';
        $sessionData = 'user_data';
        
        $this->cache->expects($this->once())
            ->method('set')
            ->willReturn(false);
        
        $result = $this->sessionHandler->write($sessionId, $sessionData);
        $this->assertFalse($result);
    }
    
    public function testDestroy(): void
    {
        $sessionId = 'test_session_id';
        
        $this->cache->expects($this->once())
            ->method('delete')
            ->with('sess:' . $sessionId)
            ->willReturn(true);
        
        $this->cache->expects($this->once())
            ->method('delete')
            ->with('sess:lock:' . $sessionId)
            ->willReturn(true);
        
        $result = $this->sessionHandler->destroy($sessionId);
        $this->assertTrue($result);
    }
    
    public function testDestroyFailure(): void
    {
        $sessionId = 'test_session_id';
        
        $this->cache->expects($this->once())
            ->method('delete')
            ->with('sess:' . $sessionId)
            ->willReturn(false);
        
        $result = $this->sessionHandler->destroy($sessionId);
        $this->assertFalse($result);
    }
    
    public function testGc(): void
    {
        $maxLifetime = 3600;
        
        $result = $this->sessionHandler->gc($maxLifetime);
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(0, $result);
    }
    
    public function testUpdateTimestamp(): void
    {
        $sessionId = 'test_session_id';
        $sessionData = 'user_data';
        
        $this->cache->expects($this->once())
            ->method('touch')
            ->with('sess:' . $sessionId, 3600)
            ->willReturn(true);
        
        $result = $this->sessionHandler->updateTimestamp($sessionId, $sessionData);
        $this->assertTrue($result);
    }
    
    public function testValidateIdValid(): void
    {
        $sessionId = 'valid_session_id_123';
        
        $this->cache->expects($this->once())
            ->method('has')
            ->with('sess:' . $sessionId)
            ->willReturn(true);
        
        $result = $this->sessionHandler->validateId($sessionId);
        $this->assertTrue($result);
    }
    
    public function testValidateIdInvalid(): void
    {
        $sessionId = 'invalid-session-id-with-invalid-chars!';
        
        $result = $this->sessionHandler->validateId($sessionId);
        $this->assertFalse($result);
    }
    
    public function testValidateIdNotExists(): void
    {
        $sessionId = 'nonexistent_session_id';
        
        $this->cache->expects($this->once())
            ->method('has')
            ->with('sess:' . $sessionId)
            ->willReturn(false);
        
        $result = $this->sessionHandler->validateId($sessionId);
        $this->assertFalse($result);
    }
    
    public function testCreateSid(): void
    {
        $sessionId = $this->sessionHandler->createSid();
        
        $this->assertIsString($sessionId);
        $this->assertNotEmpty($sessionId);
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9+\/=]+$/', $sessionId);
    }
    
    public function testLock(): void
    {
        $sessionId = 'test_session_id';
        
        $this->cache->expects($this->once())
            ->method('add')
            ->with('sess:lock:' . $sessionId, $this->anything(), 30)
            ->willReturn(true);
        
        $result = $this->sessionHandler->lock($sessionId);
        $this->assertTrue($result);
    }
    
    public function testLockTimeout(): void
    {
        $sessionId = 'test_session_id';
        
        $this->cache->expects($this->atLeast(1))
            ->method('add')
            ->with('sess:lock:' . $sessionId, $this->anything(), 1)
            ->willReturn(false);
        
        $result = $this->sessionHandler->lock($sessionId, 1);
        $this->assertFalse($result);
    }
    
    public function testUnlock(): void
    {
        $sessionId = 'test_session_id';
        
        $this->cache->expects($this->once())
            ->method('delete')
            ->with('sess:lock:' . $sessionId)
            ->willReturn(true);
        
        $result = $this->sessionHandler->unlock($sessionId);
        $this->assertTrue($result);
    }
    
    public function testIsLocked(): void
    {
        $sessionId = 'test_session_id';
        
        $this->cache->expects($this->once())
            ->method('has')
            ->with('sess:lock:' . $sessionId)
            ->willReturn(true);
        
        $result = $this->sessionHandler->isLocked($sessionId);
        $this->assertTrue($result);
    }
    
    public function testIsNotLocked(): void
    {
        $sessionId = 'test_session_id';
        
        $this->cache->expects($this->once())
            ->method('has')
            ->with('sess:lock:' . $sessionId)
            ->willReturn(false);
        
        $result = $this->sessionHandler->isLocked($sessionId);
        $this->assertFalse($result);
    }
    
    public function testGetMetadata(): void
    {
        $sessionId = 'test_session_id';
        $sessionData = [
            'data' => 'user_data',
            'created_at' => 1234567890,
            'updated_at' => 1234567900,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestAgent',
        ];
        
        $this->cache->expects($this->once())
            ->method('get')
            ->with('sess:' . $sessionId)
            ->willReturn($sessionData);
        
        $result = $this->sessionHandler->getMetadata($sessionId);
        
        $this->assertIsArray($result);
        $this->assertSame(1234567890, $result['created_at']);
        $this->assertSame(1234567900, $result['updated_at']);
        $this->assertSame('127.0.0.1', $result['ip_address']);
        $this->assertSame('TestAgent', $result['user_agent']);
        $this->assertArrayNotHasKey('data', $result);
    }
    
    public function testGetMetadataNotFound(): void
    {
        $sessionId = 'test_session_id';
        
        $this->cache->expects($this->once())
            ->method('get')
            ->with('sess:' . $sessionId)
            ->willReturn(null);
        
        $result = $this->sessionHandler->getMetadata($sessionId);
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
    
    public function testGetStats(): void
    {
        $stats = $this->sessionHandler->getStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('active_locks', $stats);
        $this->assertArrayHasKey('max_lifetime', $stats);
        $this->assertArrayHasKey('lock_timeout', $stats);
        $this->assertArrayHasKey('prefix', $stats);
        $this->assertSame(0, $stats['active_locks']);
        $this->assertSame(3600, $stats['max_lifetime']);
        $this->assertSame(30, $stats['lock_timeout']);
        $this->assertSame('sess:', $stats['prefix']);
    }
    
    public function testTouch(): void
    {
        $sessionId = 'test_session_id';
        
        $this->cache->expects($this->once())
            ->method('touch')
            ->with('sess:' . $sessionId, 3600)
            ->willReturn(true);
        
        $result = $this->sessionHandler->touch($sessionId);
        $this->assertTrue($result);
    }
    
    public function testGetTtl(): void
    {
        $sessionId = 'test_session_id';
        
        $this->cache->expects($this->once())
            ->method('getTtl')
            ->with('sess:' . $sessionId)
            ->willReturn(1800);
        
        $result = $this->sessionHandler->getTtl($sessionId);
        $this->assertSame(1800, $result);
    }
    
    public function testSetTtl(): void
    {
        $sessionId = 'test_session_id';
        $ttl = 7200;
        
        $this->cache->expects($this->once())
            ->method('touch')
            ->with('sess:' . $sessionId, $ttl)
            ->willReturn(true);
        
        $result = $this->sessionHandler->setTtl($sessionId, $ttl);
        $this->assertTrue($result);
    }
}