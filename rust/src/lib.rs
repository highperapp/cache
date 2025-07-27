use std::collections::HashMap;
use std::ffi::{CStr, CString};
use std::os::raw::c_char;
use std::sync::{Arc, Mutex};
use std::time::{SystemTime, UNIX_EPOCH};

use redis::{Commands, Connection, RedisResult};
use serde::{Deserialize, Serialize};

/// Cache entry with TTL support
#[derive(Debug, Clone, Serialize, Deserialize)]
struct CacheEntry {
    value: String,
    expires_at: Option<u64>,
    created_at: u64,
}

impl CacheEntry {
    fn new(value: String, ttl: u64) -> Self {
        let now = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap()
            .as_secs();
        
        let expires_at = if ttl > 0 { Some(now + ttl) } else { None };
        
        Self {
            value,
            expires_at,
            created_at: now,
        }
    }
    
    fn is_expired(&self) -> bool {
        if let Some(expires_at) = self.expires_at {
            let now = SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_secs();
            now > expires_at
        } else {
            false
        }
    }
}

/// Global cache storage for memory operations
static MEMORY_CACHE: Mutex<Option<HashMap<String, CacheEntry>>> = Mutex::new(None);

/// Initialize memory cache
fn init_memory_cache() {
    let mut cache = MEMORY_CACHE.lock().unwrap();
    if cache.is_none() {
        *cache = Some(HashMap::new());
    }
}

/// Convert C string to Rust string
unsafe fn c_str_to_string(c_str: *const c_char) -> Result<String, std::str::Utf8Error> {
    if c_str.is_null() {
        return Ok(String::new());
    }
    
    let cstr = CStr::from_ptr(c_str);
    cstr.to_str().map(|s| s.to_string())
}

/// Convert Rust string to C string
fn string_to_c_str(s: String) -> *mut c_char {
    match CString::new(s) {
        Ok(c_string) => c_string.into_raw(),
        Err(_) => std::ptr::null_mut(),
    }
}

/// Free C string memory
#[no_mangle]
pub extern "C" fn highper_cache_free_string(ptr: *mut c_char) {
    if !ptr.is_null() {
        unsafe {
            let _ = CString::from_raw(ptr);
        }
    }
}

/// Get library version
#[no_mangle]
pub extern "C" fn highper_cache_version() -> *mut c_char {
    string_to_c_str("1.0.0".to_string())
}

/// Memory cache operations
#[no_mangle]
pub extern "C" fn highper_cache_memory_set(
    key: *const c_char,
    value: *const c_char,
    ttl: u64,
) -> bool {
    unsafe {
        if let (Ok(key_str), Ok(value_str)) = (c_str_to_string(key), c_str_to_string(value)) {
            init_memory_cache();
            
            let mut cache = MEMORY_CACHE.lock().unwrap();
            if let Some(ref mut map) = *cache {
                let entry = CacheEntry::new(value_str, ttl);
                map.insert(key_str, entry);
                return true;
            }
        }
    }
    false
}

#[no_mangle]
pub extern "C" fn highper_cache_memory_get(key: *const c_char) -> *mut c_char {
    unsafe {
        if let Ok(key_str) = c_str_to_string(key) {
            init_memory_cache();
            
            let mut cache = MEMORY_CACHE.lock().unwrap();
            if let Some(ref mut map) = *cache {
                if let Some(entry) = map.get(&key_str) {
                    if entry.is_expired() {
                        map.remove(&key_str);
                        return std::ptr::null_mut();
                    }
                    return string_to_c_str(entry.value.clone());
                }
            }
        }
    }
    std::ptr::null_mut()
}

#[no_mangle]
pub extern "C" fn highper_cache_memory_delete(key: *const c_char) -> bool {
    unsafe {
        if let Ok(key_str) = c_str_to_string(key) {
            init_memory_cache();
            
            let mut cache = MEMORY_CACHE.lock().unwrap();
            if let Some(ref mut map) = *cache {
                return map.remove(&key_str).is_some();
            }
        }
    }
    false
}

#[no_mangle]
pub extern "C" fn highper_cache_memory_clear() -> bool {
    init_memory_cache();
    
    let mut cache = MEMORY_CACHE.lock().unwrap();
    if let Some(ref mut map) = *cache {
        map.clear();
        return true;
    }
    false
}

#[no_mangle]
pub extern "C" fn highper_cache_memory_exists(key: *const c_char) -> bool {
    unsafe {
        if let Ok(key_str) = c_str_to_string(key) {
            init_memory_cache();
            
            let mut cache = MEMORY_CACHE.lock().unwrap();
            if let Some(ref mut map) = *cache {
                if let Some(entry) = map.get(&key_str) {
                    if entry.is_expired() {
                        map.remove(&key_str);
                        return false;
                    }
                    return true;
                }
            }
        }
    }
    false
}

#[no_mangle]
pub extern "C" fn highper_cache_memory_cleanup() -> u64 {
    init_memory_cache();
    
    let mut cache = MEMORY_CACHE.lock().unwrap();
    if let Some(ref mut map) = *cache {
        let initial_count = map.len();
        map.retain(|_, entry| !entry.is_expired());
        return (initial_count - map.len()) as u64;
    }
    0
}

#[no_mangle]
pub extern "C" fn highper_cache_memory_count() -> u64 {
    init_memory_cache();
    
    let cache = MEMORY_CACHE.lock().unwrap();
    if let Some(ref map) = *cache {
        return map.len() as u64;
    }
    0
}

/// Batch operations for improved performance
#[no_mangle]
pub extern "C" fn highper_cache_memory_set_multiple(
    keys: *const *const c_char,
    values: *const *const c_char,
    ttls: *const u64,
    count: usize,
) -> u64 {
    unsafe {
        init_memory_cache();
        
        let mut cache = MEMORY_CACHE.lock().unwrap();
        if let Some(ref mut map) = *cache {
            let mut success_count = 0u64;
            
            for i in 0..count {
                let key_ptr = *keys.add(i);
                let value_ptr = *values.add(i);
                let ttl = *ttls.add(i);
                
                if let (Ok(key_str), Ok(value_str)) = (
                    c_str_to_string(key_ptr),
                    c_str_to_string(value_ptr),
                ) {
                    let entry = CacheEntry::new(value_str, ttl);
                    map.insert(key_str, entry);
                    success_count += 1;
                }
            }
            
            return success_count;
        }
    }
    0
}

#[no_mangle]
pub extern "C" fn highper_cache_memory_get_multiple(
    keys: *const *const c_char,
    count: usize,
) -> *mut c_char {
    unsafe {
        init_memory_cache();
        
        let mut cache = MEMORY_CACHE.lock().unwrap();
        if let Some(ref mut map) = *cache {
            let mut results = HashMap::new();
            
            for i in 0..count {
                let key_ptr = *keys.add(i);
                if let Ok(key_str) = c_str_to_string(key_ptr) {
                    if let Some(entry) = map.get(&key_str) {
                        if !entry.is_expired() {
                            results.insert(key_str, entry.value.clone());
                        } else {
                            map.remove(&key_str);
                        }
                    }
                }
            }
            
            if let Ok(json) = serde_json::to_string(&results) {
                return string_to_c_str(json);
            }
        }
    }
    std::ptr::null_mut()
}

/// Redis operations (basic implementation - would need connection management)
#[no_mangle]
pub extern "C" fn highper_cache_redis_ping(
    host: *const c_char,
    port: u16,
) -> bool {
    unsafe {
        if let Ok(host_str) = c_str_to_string(host) {
            let connection_string = format!("redis://{}:{}", host_str, port);
            
            if let Ok(client) = redis::Client::open(connection_string) {
                if let Ok(mut conn) = client.get_connection() {
                    let result: RedisResult<String> = conn.ping();
                    return result.is_ok();
                }
            }
        }
    }
    false
}

/// Compression utilities
#[no_mangle]
pub extern "C" fn highper_cache_compress_lz4(
    data: *const c_char,
    compressed_size: *mut usize,
) -> *mut c_char {
    unsafe {
        if let Ok(data_str) = c_str_to_string(data) {
            let compressed = lz4_flex::compress_prepend_size(data_str.as_bytes());
            if !compressed_size.is_null() {
                *compressed_size = compressed.len();
            }
            
            // Convert to base64 for safe string transport
            let base64_encoded = base64_encode(&compressed);
            return string_to_c_str(base64_encoded);
        }
    }
    std::ptr::null_mut()
}

#[no_mangle]
pub extern "C" fn highper_cache_decompress_lz4(data: *const c_char) -> *mut c_char {
    unsafe {
        if let Ok(data_str) = c_str_to_string(data) {
            if let Ok(compressed) = base64_decode(&data_str) {
                if let Ok(decompressed) = lz4_flex::decompress_size_prepended(&compressed) {
                    if let Ok(result_str) = String::from_utf8(decompressed) {
                        return string_to_c_str(result_str);
                    }
                }
            }
        }
    }
    std::ptr::null_mut()
}

/// Simple base64 encoding/decoding for binary data transport
fn base64_encode(data: &[u8]) -> String {
    const CHARS: &[u8] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    let mut result = String::new();
    
    for chunk in data.chunks(3) {
        let mut buf = [0u8; 3];
        for (i, &byte) in chunk.iter().enumerate() {
            buf[i] = byte;
        }
        
        let b = ((buf[0] as u32) << 16) | ((buf[1] as u32) << 8) | (buf[2] as u32);
        
        result.push(CHARS[((b >> 18) & 63) as usize] as char);
        result.push(CHARS[((b >> 12) & 63) as usize] as char);
        result.push(if chunk.len() > 1 { CHARS[((b >> 6) & 63) as usize] as char } else { '=' });
        result.push(if chunk.len() > 2 { CHARS[(b & 63) as usize] as char } else { '=' });
    }
    
    result
}

fn base64_decode(data: &str) -> Result<Vec<u8>, String> {
    let mut result = Vec::new();
    let chars: Vec<char> = data.chars().collect();
    
    for chunk in chars.chunks(4) {
        if chunk.len() != 4 {
            return Err("Invalid base64 length".to_string());
        }
        
        let mut values = [0u8; 4];
        for (i, &ch) in chunk.iter().enumerate() {
            values[i] = match ch {
                'A'..='Z' => (ch as u8) - b'A',
                'a'..='z' => (ch as u8) - b'a' + 26,
                '0'..='9' => (ch as u8) - b'0' + 52,
                '+' => 62,
                '/' => 63,
                '=' => 0,
                _ => return Err("Invalid base64 character".to_string()),
            };
        }
        
        let b = ((values[0] as u32) << 18) | ((values[1] as u32) << 12) | ((values[2] as u32) << 6) | (values[3] as u32);
        
        result.push((b >> 16) as u8);
        if chunk[2] != '=' {
            result.push((b >> 8) as u8);
        }
        if chunk[3] != '=' {
            result.push(b as u8);
        }
    }
    
    Ok(result)
}

/// Performance benchmarking
#[no_mangle]
pub extern "C" fn highper_cache_benchmark_memory(operations: u64) -> f64 {
    use std::time::Instant;
    
    let start = Instant::now();
    
    for i in 0..operations {
        let key = format!("benchmark_key_{}", i);
        let value = format!("benchmark_value_{}", i);
        let key_c = CString::new(key).unwrap();
        let value_c = CString::new(value).unwrap();
        
        highper_cache_memory_set(key_c.as_ptr(), value_c.as_ptr(), 3600);
        let _ = highper_cache_memory_get(key_c.as_ptr());
        highper_cache_memory_delete(key_c.as_ptr());
    }
    
    let duration = start.elapsed();
    duration.as_secs_f64()
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::ffi::CString;
    
    #[test]
    fn test_memory_cache_operations() {
        let key = CString::new("test_key").unwrap();
        let value = CString::new("test_value").unwrap();
        
        // Test set and get
        assert!(highper_cache_memory_set(key.as_ptr(), value.as_ptr(), 3600));
        let result = highper_cache_memory_get(key.as_ptr());
        assert!(!result.is_null());
        
        unsafe {
            let result_str = CStr::from_ptr(result).to_str().unwrap();
            assert_eq!(result_str, "test_value");
            highper_cache_free_string(result);
        }
        
        // Test exists
        assert!(highper_cache_memory_exists(key.as_ptr()));
        
        // Test delete
        assert!(highper_cache_memory_delete(key.as_ptr()));
        assert!(!highper_cache_memory_exists(key.as_ptr()));
    }
    
    #[test]
    fn test_ttl_expiration() {
        let key = CString::new("ttl_test").unwrap();
        let value = CString::new("ttl_value").unwrap();
        
        // Set with 1 second TTL
        assert!(highper_cache_memory_set(key.as_ptr(), value.as_ptr(), 1));
        assert!(highper_cache_memory_exists(key.as_ptr()));
        
        // Sleep for 2 seconds and test expiration
        std::thread::sleep(std::time::Duration::from_secs(2));
        assert!(!highper_cache_memory_exists(key.as_ptr()));
    }
    
    #[test]
    fn test_compression() {
        let data = CString::new("Hello, World! This is a test string for compression.").unwrap();
        let mut compressed_size = 0usize;
        
        let compressed = highper_cache_compress_lz4(data.as_ptr(), &mut compressed_size);
        assert!(!compressed.is_null());
        assert!(compressed_size > 0);
        
        let decompressed = highper_cache_decompress_lz4(compressed);
        assert!(!decompressed.is_null());
        
        unsafe {
            let result_str = CStr::from_ptr(decompressed).to_str().unwrap();
            assert_eq!(result_str, "Hello, World! This is a test string for compression.");
            
            highper_cache_free_string(compressed);
            highper_cache_free_string(decompressed);
        }
    }
}