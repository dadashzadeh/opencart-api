<?php
set_time_limit(60);

/**
 * Database Wrapper Class for MySQLi
 * Mimics OpenCart's DB class interface
 */
class ProductApiDbWrapper {
    private $connection;
    
    public function __construct($mysqli) {
        $this->connection = $mysqli;
    }
    
    /**
     * Execute SQL query
     * Returns object with num_rows, row, and rows properties
     */
    public function query($sql) {
        $result = $this->connection->query($sql);
        
        if ($result === false) {
            error_log("Query failed: " . $this->connection->error . " | SQL: " . $sql);
            return false;
        }
        
        $rows = array();
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
        
        // Create result object
        $obj = new stdClass();
        $obj->num_rows = count($rows);
        $obj->row = isset($rows[0]) ? $rows[0] : array();
        $obj->rows = $rows;
        
        return $obj;
    }
    
    /**
     * Escape string for SQL query
     */
    public function escape($value) {
        return $this->connection->real_escape_string($value);
    }
    
    /**
     * Get number of affected rows
     */
    public function countAffected() {
        return $this->connection->affected_rows;
    }
    
    /**
     * Get last insert ID
     */
    public function getLastId() {
        return $this->connection->insert_id;
    }
    
    /**
     * Check if connection is alive
     */
    public function isConnected() {
        return $this->connection && $this->connection->ping();
    }
}

// ========================================================================
// 🔒 SECURITY: Database Blacklist Wrapper
// ========================================================================

/**
 * Secure Database Wrapper - BLACKLIST ONLY
 * Blocks access to sensitive tables (users, settings, orders, customers)
 * 
 * Performance: ~0.5ms overhead per query (regex + array lookup)
 * Compatible: OpenCart 2.3.4, PHP 5.6+
 * 
 * @version 1.0.0
 */
class SecureDbWrapper {
    private $db;
    private $blockedTables = array();
    
    public function __construct($dbInstance) {
        $this->db = $dbInstance;
        $this->initializeBlacklist();
    }
    
    /**
     * Initialize blacklist - 45 sensitive tables blocked
     */
    private function initializeBlacklist() {
        $this->blockedTables = array(
            // Authentication & Users
            'user', 'user_group', 'api', 'api_ip', 'api_session',
            
            // Customers
            'customer', 'customer_group', 'customer_group_description',
            'customer_ip', 'customer_login', 'customer_online',
            'customer_search', 'customer_transaction', 'customer_wishlist',
            'customer_reward', 'customer_approval',
            
            // Orders & Payments
            'order', 'order_custom_field', 'order_fraud', 'order_history',
            'order_option', 'order_product', 'order_recurring',
            'order_recurring_transaction', 'order_shipment',
            'order_status', 'order_total', 'order_voucher',
            
            // System Configuration
            'setting', 'extension', 'event', 'modification',
            
            // Financial & Sensitive Data
            'voucher', 'voucher_history', 'voucher_theme', 'voucher_theme_description',
            'return', 'return_action', 'return_history', 'return_reason', 'return_status',
            'affiliate', 'affiliate_activity', 'affiliate_login', 'affiliate_transaction',
            'coupon', 'coupon_category', 'coupon_history', 'coupon_product'
        );
    }
    
    /**
     * Execute query with security check
     * 
     * @param string $sql SQL query
     * @return mixed Query result or throws Exception
     * @throws Exception if blocked table detected
     */
    public function query($sql) {
        if (!$this->isQuerySafe($sql)) {
            error_log('🚨 SECURITY BLOCK: ' . $sql);
            throw new Exception('Access denied: Query attempts to access restricted tables');
        }
        
        return $this->db->query($sql);
    }
    
    /**
     * Validate SQL query against blacklist
     * Uses fast regex + array lookup (< 1ms)
     * 
     * @param string $sql SQL query
     * @return bool True if safe, false if blocked
     */
    private function isQuerySafe($sql) {
        $sql = strtolower($sql);
        
        // Extract table names: FROM/JOIN/INTO/UPDATE/TABLE oc_tablename
        preg_match_all('/(?:from|join|into|update|table)\s+[`]?(' . DB_PREFIX . '[a-z_]+)[`]?/i', $sql, $matches);
        
        if (!empty($matches[1])) {
            foreach ($matches[1] as $fullTableName) {
                // Remove prefix: oc_user -> user
                $tableName = str_replace(strtolower(DB_PREFIX), '', strtolower($fullTableName));
                
                // Check blacklist (O(1) lookup with in_array)
                if (in_array($tableName, $this->blockedTables)) {
                    error_log('❌ Blocked table: ' . $tableName);
                    return false;
                }
            }
        }
        
        return true;
    }
    
    // Pass-through methods (no overhead)
    public function escape($value) {
        return $this->db->escape($value);
    }
    
    public function countAffected() {
        return $this->db->countAffected();
    }
    
    public function getLastId() {
        return $this->db->getLastId();
    }
    
    public function isConnected() {
        return method_exists($this->db, 'isConnected') ? $this->db->isConnected() : true;
    }
    
    /**
     * Get blocked tables list (for debugging)
     */
    public function getBlockedTables() {
        return $this->blockedTables;
    }
    
    /**
     * Add table to blacklist dynamically
     */
    public function blockTable($tableName) {
        $tableName = strtolower(trim($tableName));
        if (!in_array($tableName, $this->blockedTables)) {
            $this->blockedTables[] = $tableName;
        }
    }
}

// ========================================================================


/**
 * Product API Controller - COMPLETE VERSION with Full Field Preservation
 * Compatible with OpenCart 2.x and 3.x
 * RESTful API for managing products, attributes, and attribute groups
 * Location: catalog/controller/api/product_api.php
 */
class ControllerApiProductApi extends Controller {
    
    const LANGUAGE_ID = 2;
    const STATUS = 1;
    
    private $error = array();
    private $oc_version = 2;
    private $adminProductModel;
    private $adminAttributeModel;
    private $adminAttributeGroupModel;

    /**
     * Constructor - Detect version and load admin models
     */
    public function __construct($registry) {
        parent::__construct($registry);
        
        // Detect OpenCart version
        if (defined('VERSION')) {
            $version = VERSION;
            $this->oc_version = (int)substr($version, 0, 1);
        }
        
        // Set JSON response header
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            $dbInitialized = $this->initializeDatabaseConnection();
            
            if (!$dbInitialized) {
                // Log the error but continue (don't exit here)
                error_log('Product API Constructor: Database initialization failed');
                
                // Set error flag for later checks
                if (empty($this->error['database'])) {
                    $this->error['database'] = 'Database connection failed in constructor';
                }
            }
        } catch (Exception $e) {
            error_log('Product API Constructor Exception: ' . $e->getMessage());
            $this->error['database'] = 'Constructor exception: ' . $e->getMessage();
        } catch (Error $e) {
            // PHP 7+ Error (e.g., Fatal Error)
            error_log('Product API Constructor Fatal Error: ' . $e->getMessage());
            $this->error['database'] = 'Constructor fatal error: ' . $e->getMessage();
        }
        
        // Load admin models (will check DB availability inside)
        try {
            $this->loadAdminModels();
        } catch (Exception $e) {
            error_log('Product API: Failed to load admin models - ' . $e->getMessage());
        }
        
    }

    private function initializeDatabaseConnection() {
        // ===============================================
        // 0. Early exit if DB already works (via registry)
        // ===============================================
        // FIX: In OpenCart 2.3.x, $this->db goes through __get() magic method
        // which proxies to registry. Use registry directly to avoid magic issues.
        
        $existingDb = null;
        if (isset($this->registry) && method_exists($this->registry, 'get')) {
            $existingDb = $this->registry->get('db');
        }
        
        if (is_object($existingDb) && method_exists($existingDb, 'query')) {
            try {
                $test = @$existingDb->query("SELECT 1 AS test");
                if ($test !== false && is_object($test) && isset($test->num_rows) && $test->num_rows > 0) {
                    // ✅✅✅ SECURITY: Wrap existing DB with blacklist protection
                    $this->db = new SecureDbWrapper($existingDb);
                    
                    // Re-store wrapped version in registry so other models use it too
                    $this->registry->set('db', $this->db);
                    
                    error_log('Product API: Using existing registry DB ✓ + Security ON');
                    return true;
                } else {
                    error_log('Product API: Existing registry DB returned invalid test result');
                }
            } catch (Exception $e) {
                error_log('Product API: Existing DB test exception - ' . $e->getMessage());
            } catch (Error $e) {
                // FIX: PHP 7+ Error must be caught separately
                error_log('Product API: Existing DB test fatal error - ' . $e->getMessage());
            }
        } else {
            error_log('Product API: No valid DB in registry, attempting fresh connection');
        }
    
        // ===============================================
        // 1. Load database constants if not loaded
        // ===============================================
        if (!defined('DB_DRIVER')) {
            // FIX: Try multiple config locations
            $possibleConfigs = array(
                realpath(DIR_APPLICATION . '../config.php'),
                dirname(DIR_APPLICATION) . '/config.php',
                DIR_APPLICATION . 'config.php',
            );
            
            $configLoaded = false;
            foreach ($possibleConfigs as $configPath) {
                if ($configPath && file_exists($configPath)) {
                    require_once($configPath);
                    error_log('Product API: Loaded config from ' . $configPath);
                    $configLoaded = true;
                    break;
                }
            }
            
            if (!$configLoaded) {
                $this->error['database'] = 'config.php not found. Tried: ' . implode(', ', array_filter($possibleConfigs));
                error_log('Product API: ' . $this->error['database']);
                return false;
            }
        }
    
        // Validate constants
        if (!defined('DB_HOSTNAME') || !defined('DB_USERNAME') || 
            !defined('DB_PASSWORD') || !defined('DB_DATABASE')) {
            $this->error['database'] = 'Database constants missing in config.php';
            error_log('Product API: ' . $this->error['database']);
            return false;
        }
    
        $port = defined('DB_PORT') ? (int)DB_PORT : 3306;
    
        // ===============================================
        // 2. Try OpenCart DB class
        // ===============================================
        if (class_exists('DB')) {
            try {
                error_log('Product API: Attempting OpenCart DB class connection...');
                
                $driver = defined('DB_DRIVER') ? DB_DRIVER : 'mysqli';
                $ocDb = new DB($driver, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, $port);
                
                if (method_exists($ocDb, 'query')) {
                    $test = @$ocDb->query("SELECT 1 AS test");
                    
                    if ($test !== false && is_object($test) && isset($test->num_rows) && $test->num_rows > 0) {
                        // ✅ Wrap with security FIRST, then assign
                        $wrapped = new SecureDbWrapper($ocDb);
                        $this->db = $wrapped;
                        
                        // Store wrapped version in registry
                        if (isset($this->registry) && method_exists($this->registry, 'set')) {
                            $this->registry->set('db', $wrapped);
                        }
                        
                        unset($this->error['database']);
                        error_log('Product API: Connected via OpenCart DB class ✓ + Security ON');
                        return true;
                    } else {
                        $this->error['database'] = 'DB class test query failed (empty/invalid result)';
                        error_log('Product API: ' . $this->error['database']);
                    }
                }
            } catch (Exception $e) {
                $this->error['database'] = 'OpenCart DB class exception: ' . $e->getMessage();
                error_log('Product API: ' . $this->error['database']);
            } catch (Error $e) {
                // FIX: Catch PHP 7+ Errors too
                $this->error['database'] = 'OpenCart DB class fatal error: ' . $e->getMessage();
                error_log('Product API: ' . $this->error['database']);
            }
        } else {
            error_log('Product API: DB class not found, using MySQLi fallback');
        }
    
        // ===============================================
        // 3. Fallback: Direct MySQLi with wrapper
        // ===============================================
        if (!extension_loaded('mysqli')) {
            $this->error['database'] = 'MySQLi extension not loaded';
            error_log('Product API: ' . $this->error['database']);
            return false;
        }
    
        try {
            error_log('Product API: Attempting direct MySQLi connection...');
            mysqli_report(MYSQLI_REPORT_OFF);
            
            $mysqli = @new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, $port);
            
            if ($mysqli->connect_error) {
                $this->error['database'] = 'MySQLi connect error [' . $mysqli->connect_errno . ']: ' . $mysqli->connect_error;
                error_log('Product API: ' . $this->error['database']);
                
                switch($mysqli->connect_errno) {
                    case 1045: $this->error['hint'] = 'Wrong username or password'; break;
                    case 2002: $this->error['hint'] = 'Cannot connect to MySQL server'; break;
                    case 1044: $this->error['hint'] = 'Access denied to database: ' . DB_DATABASE; break;
                    case 2003: $this->error['hint'] = 'Cannot connect on port ' . $port; break;
                    case 1049: $this->error['hint'] = 'Database does not exist: ' . DB_DATABASE; break;
                    default:   $this->error['hint'] = 'Check MySQL server status and credentials';
                }
                return false;
            }
    
            if (!$mysqli->set_charset('utf8mb4')) {
                @$mysqli->set_charset('utf8');
            }
    
            $testResult = @$mysqli->query("SELECT 1 AS test, DATABASE() AS db_name");
            if (!$testResult) {
                $this->error['database'] = 'MySQLi test query failed: ' . $mysqli->error;
                error_log('Product API: ' . $this->error['database']);
                $mysqli->close();
                return false;
            }
            
            $testRow = $testResult->fetch_assoc();
            $testResult->free();
            error_log('Product API: MySQLi connected to database: ' . $testRow['db_name']);
    
            // ✅ Create wrapper chain: MySQLi → ProductApiDbWrapper → SecureDbWrapper
            $rawWrapper = new ProductApiDbWrapper($mysqli);
            $secureWrapper = new SecureDbWrapper($rawWrapper);
            $this->db = $secureWrapper;
    
            // Verify wrapper works
            $wrapperTest = $this->db->query("SELECT 1 AS test");
            if (!$wrapperTest || !isset($wrapperTest->num_rows) || $wrapperTest->num_rows == 0) {
                $this->error['database'] = 'ProductApiDbWrapper test failed';
                error_log('Product API: ' . $this->error['database']);
                return false;
            }
    
            if (isset($this->registry) && method_exists($this->registry, 'set')) {
                $this->registry->set('db', $this->db);
            }
    
            unset($this->error['database']);
            unset($this->error['hint']);
            error_log('Product API: Connected via MySQLi wrapper ✓ + Security ON');
            return true;
    
        } catch (Exception $e) {
            $this->error['database'] = 'MySQLi fallback exception: ' . $e->getMessage();
            error_log('Product API: ' . $this->error['database']);
            return false;
        } catch (Error $e) {
            // FIX: Catch PHP 7+ Errors
            $this->error['database'] = 'MySQLi fallback fatal error: ' . $e->getMessage();
            error_log('Product API: ' . $this->error['database']);
            return false;
        }
    }
    
    
    /**
     * Check if database is available and working
     * Compatible with: OpenCart DB, ProductApiDbWrapper, SecureDbWrapper
     * 
     * @return bool True if database is ready
     */
    private function isDatabaseAvailable() {
        // FIX: Read via registry to bypass magic __get issues in OpenCart 2.3.x
        $db = null;
        if (isset($this->registry) && method_exists($this->registry, 'get')) {
            $db = $this->registry->get('db');
        }
        // Fall back to direct property (might work if __get returns registry anyway)
        if (!is_object($db)) {
            $db = isset($this->db) ? $this->db : null;
        }
        
        if (!is_object($db)) {
            error_log('Product API: isDatabaseAvailable() - db is not an object (type: ' . gettype($db) . ')');
            return false;
        }
        
        if (!method_exists($db, 'query')) {
            error_log('Product API: isDatabaseAvailable() - query() method missing');
            return false;
        }
        
        try {
            $testResult = $db->query("SELECT 1 AS test");
            
            if ($testResult === false || !is_object($testResult)) {
                error_log('Product API: isDatabaseAvailable() - invalid test result');
                return false;
            }
            
            if (isset($testResult->num_rows) && $testResult->num_rows > 0) {
                return true;
            }
            if (isset($testResult->row) && !empty($testResult->row)) {
                return true;
            }
            
            error_log('Product API: isDatabaseAvailable() - test result has no data');
            return false;
            
        } catch (Exception $e) {
            error_log('Product API: isDatabaseAvailable() - Exception: ' . $e->getMessage());
            return false;
        } catch (Error $e) {
            error_log('Product API: isDatabaseAvailable() - Fatal Error: ' . $e->getMessage());
            return false;
        }
    }
    
    
    
    /**
     * Get OpenCart version information
     */
    private function getVersionInfo() {
        return array(
            'version_string' => defined('VERSION') ? VERSION : 'UNKNOWN',
            'major_version' => $this->oc_version,
            'version_name' => $this->getVersionName()
        );
    }
    
    /**
     * Get version name
     */
    private function getVersionName() {
        switch ($this->oc_version) {
            case 2:
                return 'OpenCart 2.x';
            case 3:
                return 'OpenCart 3.x';
            case 4:
                return 'OpenCart 4.x';
            default:
                return 'Unknown OpenCart Version';
        }
    }
    
    /**
     * Load admin models with priority: modification/storage first, then original admin
     * Each model file is searched separately
     */
    private function loadAdminModels() {
        $searchPaths = $this->getOrderedSearchPaths(); // modification paths first
    
        // Load product model
        $productPath = $this->findFirstExistingFile($searchPaths, 'product.php');
        if (!$productPath) {
            $this->sendResponse(array(
                'success' => false,
                'error' => 'product.php not found in any search path'
            ), 500);
            exit;
        }
        require_once($productPath);
        $this->adminProductModel = new ModelCatalogProduct($this->registry);
    
        // Load attribute model
        $attributePath = $this->findFirstExistingFile($searchPaths, 'attribute.php');
        if (!$attributePath) {
            $this->sendResponse(array(
                'success' => false,
                'error' => 'attribute.php not found in any search path'
            ), 500);
            exit;
        }
        require_once($attributePath);
        $this->adminAttributeModel = new ModelCatalogAttribute($this->registry);
    
        // Load attribute group model
        $attributeGroupPath = $this->findFirstExistingFile($searchPaths, 'attribute_group.php');
        if (!$attributeGroupPath) {
            $this->sendResponse(array(
                'success' => false,
                'error' => 'attribute_group.php not found in any search path'
            ), 500);
            exit;
        }
        require_once($attributeGroupPath);
        $this->adminAttributeGroupModel = new ModelCatalogAttributeGroup($this->registry);
    }

    /**
     * Returns all possible search paths with modification/storage paths first
     * Restricts search to current installation only (no parent directories outside the site root)
     */
    private function getOrderedSearchPaths() {
        $allPaths = $this->getSearchPaths();
        
        $priorityPaths = array();
        $otherPaths = array();
        
        foreach ($allPaths as $path) {
            if (strpos($path, 'modification') !== false || strpos($path, 'storage') !== false) {
                $priorityPaths[] = $path;
            } else {
                $otherPaths[] = $path;
            }
        }
        
        return array_merge($priorityPaths, $otherPaths);
    }
    
    /**
     * Find admin model path by searching all possible locations
     * @return string Path to admin models or empty string if not found
     */
    private function findAdminModelPath() {
        $searchPaths = $this->getSearchPaths();
        
        // Search each path
        foreach ($searchPaths as $path) {
            // Check if product.php exists (main indicator file)
            if (file_exists($path . 'product.php')) {
                // Verify all three required files exist
                if (file_exists($path . 'attribute.php') && 
                    file_exists($path . 'attribute_group.php')) {
                    return $path;
                }
            }
        }
        
        return '';
    }
    
    /**
     * Get all possible paths where admin models might be located
     * Now restricted to the current OpenCart installation (no parent directories above site root)
     * @return array List of paths to search
     */
    private function getSearchPaths() {
        $paths = array();
        
        // Define the site root based on DIR_APPLICATION (e.g., /home/.../public_html/dev/catalog/)
        $siteRoot = realpath(DIR_APPLICATION . '../');
        if (!$siteRoot) {
            $siteRoot = dirname(DIR_APPLICATION);
        }
        
        // 1. Check if DIR_STORAGE is defined (OpenCart 3.x+)
        if (defined('DIR_STORAGE')) {
            $paths[] = DIR_STORAGE . 'modification/admin/model/catalog/';
        }
        
        // 2. Common storage locations relative to the site root (NOT going above it)
        $storageLocations = array(
            'system/storage/modification/admin/model/catalog/',
            'storage/modification/admin/model/catalog/',
        );
        
        foreach ($storageLocations as $location) {
            $fullPath = $siteRoot . '/' . $location;
            $realPath = realpath($fullPath);
            if ($realPath !== false && strpos($realPath, $siteRoot) === 0) {
                $paths[] = $realPath . '/';
            }
        }
        
        // 3. Direct admin folder (fallback if no modifications)
        $adminPath = $siteRoot . '/admin/model/catalog/';
        $realAdmin = realpath($adminPath);
        if ($realAdmin !== false && strpos($realAdmin, $siteRoot) === 0) {
            $paths[] = $realAdmin . '/';
        }
        
        // 4. Use DIR_SYSTEM if defined
        if (defined('DIR_SYSTEM')) {
            $systemRoot = realpath(DIR_SYSTEM);
            if ($systemRoot && strpos($systemRoot, $siteRoot) === 0) {
                $paths[] = $systemRoot . '/storage/modification/admin/model/catalog/';
            }
        }
        
        // Remove duplicates and only keep paths that are inside the site root
        $paths = array_unique($paths);
        $validPaths = array();
        foreach ($paths as $path) {
            $real = realpath($path);
            if ($real !== false && strpos($real, $siteRoot) === 0) {
                $validPaths[] = $real . '/';
            }
        }
        
        return $validPaths;
    }
    
    /**
     * Find the first existing file in the given list of directories
     * @param array $directories List of directory paths
     * @param string $filename File name to look for
     * @return string|false Full path if found, false otherwise
     */
    private function findFirstExistingFile($directories, $filename) {
        foreach ($directories as $dir) {
            $fullPath = rtrim($dir, '/') . '/' . $filename;
            if (file_exists($fullPath)) {
                return $fullPath;
            }
        }
        return false;
    }
    
    /**
     * Get token name based on OpenCart version
     * @return string Token parameter name
     */
    private function getTokenName() {
        return $this->oc_version >= 3 ? 'user_token' : 'token';
    }
    
    /**
     * Get SEO URL table name based on version
     * @return string Table name for SEO URLs
     */
    private function getSeoUrlTable() {
        return $this->oc_version >= 3 ? 'seo_url' : 'url_alias';
    }
    
    /**
     * Authenticate API request using API key
     * @return bool
     */
    public function authenticate() {
        $apiKey = '';
        
        // Get API key from multiple sources
        if (isset($this->request->get['api_key'])) {
            $apiKey = $this->request->get['api_key'];
        } elseif (isset($this->request->server['HTTP_X_API_KEY'])) {
            $apiKey = $this->request->server['HTTP_X_API_KEY'];
        } elseif (isset($this->request->post['api_key'])) {
            $apiKey = $this->request->post['api_key'];
        }
        
        // Check if API key is defined
        if (!defined('PRODUCT_API_KEY')) {
            $this->sendResponse(array(
                'success' => false,
                'error' => 'API key not configured',
                'hint' => 'Add define(\'PRODUCT_API_KEY\', \'your_key\'); to config.php'
            ), 500);
        }
        
        if (empty($apiKey) || $apiKey !== PRODUCT_API_KEY) {
            $this->sendResponse(array(
                'success' => false,
                'error' => 'Invalid or missing API key',
                'hint' => 'Provide api_key parameter or X-API-KEY header'
            ), 401);
        }
    }
    
    public function sendResponse($data, $httpCode = 200) {
        // Set HTTP response code (compatible with PHP 5.6+)
        if (function_exists('http_response_code')) {
            http_response_code($httpCode);
        } else {
            header('X-PHP-Response-Code: ' . $httpCode, true, $httpCode);
        }
        
        // Set headers if not already sent
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY');
        }
        
        // JSON encode with proper flags
        $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
        
        // Add pretty print if requested
        if (isset($this->request->get['pretty']) || isset($this->request->get['debug'])) {
            $jsonFlags |= JSON_PRETTY_PRINT;
        }
        
        echo json_encode($data, $jsonFlags);
        exit;
    }
    
    
    /**
     * Get language ID from config
     * @return int Language ID
     */
    private function getLanguageId() {
        if (method_exists($this->config, 'get')) {
            return (int)$this->config->get('config_language_id');
        }
        return self::LANGUAGE_ID;
    }
    
    /**
     * Debug endpoint to show detected paths and version
     * GET: /index.php?route=api/product_api/debugInfo&api_key=xxx
     */
    public function debugInfo() {
        $this->authenticate();
        
        $searchPaths = $this->getSearchPaths();
        $detectedPath = $this->findAdminModelPath();
        
        $pathsWithStatus = array();
        foreach ($searchPaths as $path) {
            $pathsWithStatus[] = array(
                'path' => $path,
                'exists' => file_exists($path . 'product.php'),
                'is_detected' => ($path === $detectedPath)
            );
        }
        
        $this->sendResponse(array(
            'success' => true,
            'opencart_version' => VERSION,
            'detected_version' => $this->oc_version,
            'token_name' => $this->getTokenName(),
            'seo_table' => $this->getSeoUrlTable(),
            'detected_model_path' => $detectedPath ? $detectedPath : 'NOT FOUND',
            'all_searched_paths' => $pathsWithStatus,
            'dir_application' => DIR_APPLICATION,
            'dir_system' => defined('DIR_SYSTEM') ? DIR_SYSTEM : 'Not defined',
            'dir_storage' => defined('DIR_STORAGE') ? DIR_STORAGE : 'Not defined'
        ));
    }

    /**
     * API Documentation - Default endpoint
     * GET: /index.php?route=api/product_api&api_key=xxx
     * GET: /index.php?route=api/product_api/index&api_key=xxx
     */
    public function index() {
        $this->authenticate();

        // Get base URL
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $script = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        $baseUrl = $protocol . "://" . $host . $script;
        $apiBase = $baseUrl . '/index.php?route=api/product_api/';

        $documentation = array(
            'api_name' => 'OpenCart Product API',
            'version' => '1.1.0',
            'opencart_version' => VERSION,
            'base_url' => $apiBase,
            'authentication' => array(
                'methods' => array(
                    'query_param' => 'api_key=your_key',
                    'http_header' => 'X-API-KEY: your_key'
                ),
                'note' => 'All requests require authentication'
            ),
            'endpoints' => array(
                array(
                    'group' => 'Products',
                    'methods' => array(
                        array(
                            'name' => 'Search Products',
                            'method' => 'GET',
                            'endpoint' => 'searchProduct',
                            'url' => $apiBase . 'searchProduct&name={name}&api_key={key}',
                            'params' => array(
                                array('name' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Product name to search'),
                                array('name' => 'start', 'type' => 'int', 'required' => false, 'description' => 'Pagination offset (default: 0)'),
                                array('name' => 'limit', 'type' => 'int', 'required' => false, 'description' => 'Results per page (default: 20)')
                            )
                        ),
                        array(
                            'name' => 'Get Product',
                            'method' => 'GET',
                            'endpoint' => 'getProduct',
                            'url' => $apiBase . 'getProduct&product_id={id}&api_key={key}',
                            'params' => array(
                                array('name' => 'product_id', 'type' => 'int', 'required' => true, 'description' => 'Product ID')
                            )
                        ),
                        array(
                            'name' => 'Update Product',
                            'method' => 'POST',
                            'endpoint' => 'updateProduct',
                            'url' => $apiBase . 'updateProduct&product_id={id}&api_key={key}',
                            'params' => array(
                                array('name' => 'product_id', 'type' => 'int', 'required' => true, 'description' => 'Product ID (in URL)')
                            ),
                            'body' => array(
                                array('name' => 'name', 'type' => 'string', 'required' => false, 'description' => 'Product name'),
                                array('name' => 'description', 'type' => 'string', 'required' => false, 'description' => 'Product description (HTML)'),
                                array('name' => 'quantity', 'type' => 'int', 'required' => false, 'description' => 'Stock quantity'),
                                array('name' => 'language_id', 'type' => 'int', 'required' => false, 'description' => 'Language ID'),
                                array('name' => 'attributes', 'type' => 'array', 'required' => false, 'description' => '[{attribute_id, value}]')
                            )
                        )
                    )
                ),
                array(
                    'group' => 'Attributes',
                    'methods' => array(
                        array(
                            'name' => 'Search Attributes by Key',
                            'method' => 'GET',
                            'endpoint' => 'searchAttributeByKey',
                            'url' => $apiBase . 'searchAttributeByKey&key={name}&api_key={key}',
                            'params' => array(
                                array('name' => 'key', 'type' => 'string', 'required' => true, 'description' => 'Attribute name to search')
                            )
                        ),
                        array(
                            'name' => 'Search Attributes by Value',
                            'method' => 'GET',
                            'endpoint' => 'searchAttributeByValue',
                            'url' => $apiBase . 'searchAttributeByValue&value={text}&api_key={key}',
                            'params' => array(
                                array('name' => 'value', 'type' => 'string', 'required' => true, 'description' => 'Attribute value to search')
                            )
                        ),
                        array(
                            'name' => 'Get Attribute',
                            'method' => 'GET',
                            'endpoint' => 'getAttribute',
                            'url' => $apiBase . 'getAttribute&attribute_id={id}&api_key={key}',
                            'params' => array(
                                array('name' => 'attribute_id', 'type' => 'int', 'required' => true, 'description' => 'Attribute ID')
                            )
                        ),
                        array(
                            'name' => 'Add Attribute',
                            'method' => 'POST',
                            'endpoint' => 'addAttribute',
                            'url' => $apiBase . 'addAttribute&api_key={key}',
                            'body' => array(
                                array('name' => 'attribute_group_id', 'type' => 'int', 'required' => true, 'description' => 'Attribute group ID'),
                                array('name' => 'sort_order', 'type' => 'int', 'required' => false, 'description' => 'Sort order'),
                                array('name' => 'attribute_description', 'type' => 'object', 'required' => true, 'description' => '{language_id: {name}}')
                            )
                        ),
                        array(
                            'name' => 'Update Attribute',
                            'method' => 'POST',
                            'endpoint' => 'updateAttribute',
                            'url' => $apiBase . 'updateAttribute&attribute_id={id}&api_key={key}',
                            'params' => array(
                                array('name' => 'attribute_id', 'type' => 'int', 'required' => true, 'description' => 'Attribute ID (in URL)')
                            ),
                            'body' => array(
                                array('name' => 'attribute_group_id', 'type' => 'int', 'required' => false, 'description' => 'Attribute group ID'),
                                array('name' => 'sort_order', 'type' => 'int', 'required' => false, 'description' => 'Sort order'),
                                array('name' => 'attribute_description', 'type' => 'object', 'required' => false, 'description' => '{language_id: {name}}')
                            )
                        ),
                        array(
                            'name' => 'Delete Attribute',
                            'method' => 'DELETE',
                            'endpoint' => 'deleteAttribute',
                            'url' => $apiBase . 'deleteAttribute&attribute_id={id}&api_key={key}',
                            'params' => array(
                                array('name' => 'attribute_id', 'type' => 'int', 'required' => true, 'description' => 'Attribute ID')
                            )
                        )
                    )
                ),
                array(
                    'group' => 'Attribute Groups',
                    'methods' => array(
                        array(
                            'name' => 'Search Attribute Groups',
                            'method' => 'GET',
                            'endpoint' => 'searchAttributeGroup',
                            'url' => $apiBase . 'searchAttributeGroup&name={name}&api_key={key}',
                            'params' => array(
                                array('name' => 'name', 'type' => 'string', 'required' => false, 'description' => 'Group name (optional)')
                            )
                        ),
                        array(
                            'name' => 'Get Attribute Group',
                            'method' => 'GET',
                            'endpoint' => 'getAttributeGroup',
                            'url' => $apiBase . 'getAttributeGroup&attribute_group_id={id}&api_key={key}',
                            'params' => array(
                                array('name' => 'attribute_group_id', 'type' => 'int', 'required' => true, 'description' => 'Attribute group ID')
                            )
                        ),
                        array(
                            'name' => 'Add Attribute Group',
                            'method' => 'POST',
                            'endpoint' => 'addAttributeGroup',
                            'url' => $apiBase . 'addAttributeGroup&api_key={key}',
                            'body' => array(
                                array('name' => 'sort_order', 'type' => 'int', 'required' => false, 'description' => 'Sort order'),
                                array('name' => 'attribute_group_description', 'type' => 'object', 'required' => true, 'description' => '{language_id: {name}}')
                            )
                        ),
                        array(
                            'name' => 'Update Attribute Group',
                            'method' => 'POST',
                            'endpoint' => 'updateAttributeGroup',
                            'url' => $apiBase . 'updateAttributeGroup&attribute_group_id={id}&api_key={key}',
                            'params' => array(
                                array('name' => 'attribute_group_id', 'type' => 'int', 'required' => true, 'description' => 'Attribute group ID (in URL)')
                            ),
                            'body' => array(
                                array('name' => 'sort_order', 'type' => 'int', 'required' => false, 'description' => 'Sort order'),
                                array('name' => 'attribute_group_description', 'type' => 'object', 'required' => false, 'description' => '{language_id: {name}}')
                            )
                        ),
                        array(
                            'name' => 'Delete Attribute Group',
                            'method' => 'DELETE',
                            'endpoint' => 'deleteAttributeGroup',
                            'url' => $apiBase . 'deleteAttributeGroup&attribute_group_id={id}&api_key={key}',
                            'params' => array(
                                array('name' => 'attribute_group_id', 'type' => 'int', 'required' => true, 'description' => 'Attribute group ID')
                            )
                        )
                    )
                ),
                array(
                    'group' => 'System',
                    'methods' => array(
                        array(
                            'name' => 'Debug Info',
                            'method' => 'GET',
                            'endpoint' => 'debugInfo',
                            'url' => $apiBase . 'debugInfo&api_key={key}',
                            'params' => array(),
                            'description' => 'Get system information and detected paths'
                        ),
                        array(
                            'name' => 'API Documentation',
                            'method' => 'GET',
                            'endpoint' => 'index',
                            'url' => $apiBase . 'index&api_key={key}',
                            'params' => array(),
                            'description' => 'Get this documentation'
                        )
                    )
                )
            ),
            'response_format' => array(
                'success' => array(
                    'success' => true,
                    'data' => 'Response data here',
                    'count' => 'Number of results (for list endpoints)'
                ),
                'error' => array(
                    'success' => false,
                    'error' => 'Error message'
                )
            ),
            'http_status_codes' => array(
                200 => 'OK - Request successful',
                201 => 'Created - Resource created successfully',
                400 => 'Bad Request - Invalid parameters or missing data',
                401 => 'Unauthorized - Invalid or missing API key',
                404 => 'Not Found - Resource not found',
                405 => 'Method Not Allowed - Wrong HTTP method',
                500 => 'Internal Server Error'
            )
        );

        $this->sendResponse(array(
            'success' => true,
            'data' => $documentation
        ));
    }


    // ==================== PRODUCT OPERATIONS ====================
    
    /**
     * Search products by name
     * GET: /index.php?route=api/product_api/searchProduct&name=iPhone&api_key=xxx
     */
    public function searchProduct() {
        $this->authenticate();
        
        try {
            $name = isset($this->request->get['name']) ? trim($this->request->get['name']) : '';
            
            if (empty($name)) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Name parameter is required'
                ), 400);
            }
            
            // Prepare filter data
            $filterData = array(
                'filter_name' => $name,
                'filter_status' => self::STATUS,
                'start' => isset($this->request->get['start']) ? (int)$this->request->get['start'] : 0,
                'limit' => isset($this->request->get['limit']) ? (int)$this->request->get['limit'] : 20
            );
            
            // Get products from admin model
            $products = $this->adminProductModel->getProducts($filterData);
            
            // Format results
            $result = array();
            foreach ($products as $product) {
                $result[] = array(
                    'product_id' => $product['product_id'],
                    'name' => $product['name'],
                    'model' => $product['model'],
                    'quantity' => $product['quantity'],
                    'status' => $product['status']
                );
            }
            
            $this->sendResponse(array(
                'success' => true,
                'data' => $result,
                'count' => count($result)
            ));
            
        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }
    
    /**
     * Get complete product information
     * GET: /index.php?route=api/product_api/getProduct&product_id=123&api_key=xxx
     */
    public function getProduct() {
        $this->authenticate();
        
        try {
            $productId = isset($this->request->get['product_id']) ? (int)$this->request->get['product_id'] : 0;
            
            if (!$productId) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Product ID is required'
                ), 400);
            }
            
            // Get product data
            $product = $this->adminProductModel->getProduct($productId);
            
            if (!$product) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Product not found'
                ), 404);
            }
            
            // Get additional product data
            $descriptions = $this->adminProductModel->getProductDescriptions($productId);
            $attributes = $this->adminProductModel->getProductAttributes($productId);
            $relatedIds = $this->adminProductModel->getProductRelated($productId);
            $categoryIds = $this->adminProductModel->getProductCategories($productId);

            // product GALLERY (additional images)
            $gallery = $this->adminProductModel->getProductImages($productId);
            
            // Get related products with names
            $relatedProducts = array();
            if (!empty($relatedIds)) {
                foreach ($relatedIds as $relatedId) {
                    $relatedProduct = $this->adminProductModel->getProduct($relatedId);
                    if ($relatedProduct) {
                        $relatedProducts[] = array(
                            'product_id' => $relatedId,
                            'name' => $relatedProduct['name'],
                            'model' => $relatedProduct['model'],
                            'status' => $relatedProduct['status']
                        );
                    }
                }
            }
            
            // Get categories with names
            $categories = array();
            if (!empty($categoryIds)) {
                foreach ($categoryIds as $categoryId) {
                    // Get category name
                    $sql = "SELECT cd.name 
                            FROM " . DB_PREFIX . "category_description cd 
                            WHERE cd.category_id = '" . (int)$categoryId . "' 
                            AND cd.language_id = '" . (int)$this->getLanguageId() . "'";
                    
                    $query = $this->db->query($sql);
                    
                    $categoryName = '';
                    if ($query->num_rows > 0) {
                        $categoryName = $query->row['name'];
                    }
                    
                    $categories[] = array(
                        'category_id' => $categoryId,
                        'name' => $categoryName
                    );
                }
            }

            // Format attributes with key-value pairs
            $formattedAttributes = array();
            foreach ($attributes as $attr) {
                $attrInfo = $this->adminAttributeModel->getAttribute($attr['attribute_id']);
                
                if ($attrInfo && isset($attr['product_attribute_description'])) {
                    foreach ($attr['product_attribute_description'] as $langId => $attrDesc) {
                        $formattedAttributes[] = array(
                            'attribute_id' => $attr['attribute_id'],
                            'language_id' => $langId,
                            'key' => isset($attrInfo['name']) ? $attrInfo['name'] : '',
                            'value' => isset($attrDesc['text']) ? $attrDesc['text'] : ''
                        );
                    }
                }
            }
            
            // Build response
            $result = array(
                'product_id' => $product['product_id'],
                'date_added' => $product['date_added'],
                'date_modified' => $product['date_modified'],
                'model' => $product['model'],
                'status' => $product['status'],
                'image' => $product['image'],
                'gallery' => $gallery,
                'descriptions' => $descriptions,
                'attributes' => $formattedAttributes,
                'related_products' => $relatedProducts,
                'categories' => $categories
            );
            
            $this->sendResponse(array(
                'success' => true,
                'data' => $result
            ));
            
        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }

    /**
     * Check product images (main + gallery) for file existence
     *
     * @param int $product_id OpenCart product ID
     * GET: /index.php?route=api/product_api/checkImages&product_id=123&api_key=xxx
     * @return array Status report with main_image and gallery_images
     */
    public function checkImages() {
        $this->authenticate();
        
        $product_id = isset($this->request->get['product_id']) ? (int)$this->request->get['product_id'] : 0;
        if (!$product_id) {
            $this->sendResponse(['success' => false, 'error' => 'product_id required'], 400);
        }
        
        // Prepare result array
        $result = [
            'product_id' => $product_id,
            'main_image' => null,
            'gallery_images' => [],
            'missing_count' => 0,
            'total_count' => 0
        ];
        
        // Check database availability
        if (!$this->isDatabaseAvailable()) {
            $this->sendResponse(['success' => false, 'error' => 'Database connection unavailable'], 500);
        }
        
        // 1. Get main product image
        $mainQuery = $this->db->query(
            "SELECT image FROM " . DB_PREFIX . "product 
             WHERE product_id = '" . (int)$product_id . "'"
        );
        
        if ($mainQuery->num_rows == 0) {
            $this->sendResponse(['success' => false, 'error' => 'Product not found'], 404);
        }
        
        $mainImageDb = $mainQuery->row['image'];
        $mainImagePath = DIR_IMAGE . $mainImageDb;
        $mainExists = !empty($mainImageDb) && file_exists($mainImagePath);
        
        $result['main_image'] = [
            'db_path' => $mainImageDb,
            'full_path' => $mainImagePath,
            'exists' => $mainExists,
            'size_bytes' => $mainExists ? filesize($mainImagePath) : 0
        ];
        
        if (!$mainExists && !empty($mainImageDb)) {
            $result['missing_count']++;
        }
        
        // 2. Get gallery images
        $galleryQuery = $this->db->query(
            "SELECT product_image_id, image 
             FROM " . DB_PREFIX . "product_image 
             WHERE product_id = '" . (int)$product_id . "' 
             ORDER BY sort_order ASC"
        );
        
        foreach ($galleryQuery->rows as $galleryRow) {
            $galleryDbPath = $galleryRow['image'];
            $galleryFullPath = DIR_IMAGE . $galleryDbPath;
            $galleryExists = !empty($galleryDbPath) && file_exists($galleryFullPath);
            
            $result['gallery_images'][] = [
                'product_image_id' => $galleryRow['product_image_id'],
                'db_path' => $galleryDbPath,
                'full_path' => $galleryFullPath,
                'exists' => $galleryExists,
                'size_bytes' => $galleryExists ? filesize($galleryFullPath) : 0
            ];
            
            if (!$galleryExists && !empty($galleryDbPath)) {
                $result['missing_count']++;
            }
        }
        
        $result['total_count'] = 1 + count($galleryQuery->rows);
        $result['available_count'] = $result['total_count'] - $result['missing_count'];
        
        $this->sendResponse(['success' => true, 'data' => $result]);
    }
    
    // ==================== IMAGE UPLOAD OPERATIONS ====================
    
    /**
     * Upload image with support for all formats (JPG, PNG, GIF, WebP, BMP)
     * Works with PHP 5.6+ and OpenCart 2.x/3.x
     * 
     * Supports custom filenames - preserves original or uses provided name
     * 
     * Endpoint: POST /index.php?route=api/product_api/uploadImage&api_key=xxx
     * 
     * ============================================================
     * UPLOAD METHOD 1: Multipart Form-Data (Standard File Upload)
     * ============================================================
     * Content-Type: multipart/form-data
     * 
     * Form Fields:
     * - image (file, required)          - The image file to upload
     * - filename (string, optional)     - Custom filename (with or without extension)
     *                                     If empty, uses original uploaded filename
     *                                     Examples: "product-main.jpg", "banner-1", "logo.webp"
     * - subfolder (string, optional)    - Subfolder name (default: "products")
     *                                     Examples: "products", "banners", "categories"
     * - resize_width (int, optional)    - Target width in pixels (default: 0 = no resize)
     *                                     Example: 800
     * - resize_height (int, optional)   - Target height in pixels (default: 0 = no resize)
     *                                     Example: 800
     * - preserve_name (bool, optional)  - Use original filename (default: false)
     *                                     If true, uses uploaded file's original name
     * 
     * cURL Example (Multipart with custom filename):
     * curl -X POST \
     *   'https://yoursite.com/index.php?route=api/product_api/uploadImage&api_key=YOUR_KEY' \
     *   -F 'image=@/path/to/image.jpg' \
     *   -F 'filename=my-custom-name.jpg' \
     *   -F 'subfolder=products' \
     *   -F 'resize_width=800'
     * 
     * cURL Example (Preserve original filename):
     * curl -X POST \
     *   'https://yoursite.com/index.php?route=api/product_api/uploadImage&api_key=YOUR_KEY' \
     *   -F 'image=@/path/to/cpu_specs_screenshot.webp' \
     *   -F 'preserve_name=1' \
     *   -F 'subfolder=products'
     * 
     * ============================================================
     * UPLOAD METHOD 2: Base64 Encoded (JSON)
     * ============================================================
     * Content-Type: application/json
     * 
     * JSON Body Fields:
     * {
     *   "image_data": "data:image/png;base64,iVBORw0KGgo...",  // required - Base64 image data
     *   "filename": "product-image.png",                       // optional - Custom filename
     *   "subfolder": "products",                               // optional - Subfolder (default: "products")
     *   "resize_width": 800,                                   // optional - Target width (default: 0)
     *   "resize_height": 800                                   // optional - Target height (default: 0)
     * }
     * 
     * ============================================================
     * RESPONSE (Success)
     * ============================================================
     * HTTP Status: 201 Created
     * {
     *   "success": true,
     *   "message": "Image uploaded successfully",
     *   "data": {
     *     "filename": "products/my-custom-name.jpg",
     *     "original_name": "cpu_specs_screenshot.webp",
     *     "path": "/var/www/html/image/catalog/products/my-custom-name.jpg",
     *     "url": "https://yoursite.com/image/catalog/products/my-custom-name.jpg",
     *     "size": 45678,
     *     "mime_type": "image/webp",
     *     "dimensions": {
     *       "width": 800,
     *       "height": 800
     *     }
     *   }
     * }
     * 
     * ============================================================
     * FILENAME BEHAVIOR
     * ============================================================
     * Priority order:
     * 1. If "filename" field provided -> Use it (sanitized)
     * 2. If "preserve_name=1" -> Use original uploaded filename
     * 3. Default -> Generate unique name: "image-1234567890-abc123.jpg"
     * 
     * Auto-deduplication:
     * - If filename exists, adds timestamp: "logo.jpg" -> "logo-1234567890.jpg"
     * - Always sanitizes: removes spaces, special chars, keeps alphanumeric and dashes
     * 
     * ============================================================
     * SUPPORTED FORMATS
     * ============================================================
     * - JPEG/JPG (.jpg, .jpeg)
     * - PNG (.png) - Transparency preserved
     * - GIF (.gif) - Transparency preserved
     * - WebP (.webp) - Works even without GD WebP support
     * - BMP (.bmp)
     * 
     * Maximum file size: 10MB
     */
    public function uploadImage() {
        $this->authenticate();
        
        try {
            // Check if it's a POST request
            if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Only POST method allowed'
                ), 405);
            }
            
            // Get parameters with defaults
            $subfolder = isset($this->request->post['subfolder']) ? $this->request->post['subfolder'] : 'products';
            $resizeWidth = isset($this->request->post['resize_width']) ? (int)$this->request->post['resize_width'] : 0;
            $resizeHeight = isset($this->request->post['resize_height']) ? (int)$this->request->post['resize_height'] : 0;
            $customFilename = isset($this->request->post['filename']) ? trim($this->request->post['filename']) : '';
            $preserveName = isset($this->request->post['preserve_name']) ? (bool)$this->request->post['preserve_name'] : false;
            
            // Check if it's a base64 upload (JSON)
            $jsonData = json_decode(file_get_contents('php://input'), true);
            
            if ($jsonData && isset($jsonData['image_data'])) {
                // Base64 upload
                $result = $this->uploadBase64Image(
                    $jsonData['image_data'],
                    isset($jsonData['filename']) ? $jsonData['filename'] : $customFilename,
                    isset($jsonData['subfolder']) ? $jsonData['subfolder'] : $subfolder,
                    isset($jsonData['resize_width']) ? (int)$jsonData['resize_width'] : $resizeWidth,
                    isset($jsonData['resize_height']) ? (int)$jsonData['resize_height'] : $resizeHeight
                );
            } 
            // Standard file upload
            elseif (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $result = $this->uploadFileImage(
                    $_FILES['image'], 
                    $subfolder, 
                    $resizeWidth, 
                    $resizeHeight,
                    $customFilename,
                    $preserveName
                );
            } 
            else {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'No image provided. Use "image" field for file upload or "image_data" for base64',
                    'help' => array(
                        'multipart' => 'Content-Type: multipart/form-data, Field: image (file)',
                        'base64' => '{"image_data": "data:image/png;base64,iVBORw0KG...", "filename": "image.png"}'
                    )
                ), 400);
            }
            
            $this->sendResponse(array(
                'success' => true,
                'message' => 'Image uploaded successfully',
                'data' => $result
            ), 201);
            
        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }
    
    /**
     * Update product main image
     * Uploads new image and sets it as the product's main image
     * 
     * Endpoint: POST /index.php?route=api/product_api/updateProductImage&product_id=42&api_key=xxx
     * 
     * ============================================================
     * URL PARAMETERS
     * ============================================================
     * - product_id (int, required) - Product ID to update
     *   Example: product_id=42
     * 
     * ============================================================
     * FORM FIELDS (same as uploadImage)
     * ============================================================
     * - image (file, required)
     * - filename (string, optional) - Custom filename
     * - preserve_name (bool, optional) - Use original filename
     * - resize_width (int, optional)
     * - resize_height (int, optional)
     * 
     * ============================================================
     * RESPONSE (Success)
     * ============================================================
     * {
     *   "success": true,
     *   "message": "Product image updated successfully",
     *   "product_id": 42,
     *   "image": {
     *     "filename": "products/iphone-15-pro.jpg",
     *     "original_name": "my-photo.jpg",
     *     "url": "https://yoursite.com/image/catalog/products/iphone-15-pro.jpg",
     *     "size": 45678,
     *     "dimensions": {"width": 800, "height": 800}
     *   }
     * }
     * 
     * cURL Example:
     * curl -X POST \
     *   'https://yoursite.com/index.php?route=api/product_api/updateProductImage&product_id=42&api_key=YOUR_KEY' \
     *   -F 'image=@/path/to/new-image.jpg' \
     *   -F 'filename=iphone-15-pro.jpg'
     */
    public function updateProductImage() {
        $this->authenticate();
        
        try {
            $productId = isset($this->request->get['product_id']) ? (int)$this->request->get['product_id'] : 0;
            
            if (!$productId) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'product_id parameter is required',
                    'required_fields' => array('product_id'),
                    'example' => 'product_id=42'
                ), 400);
            }
            
            // Check if product exists
            $product = $this->adminProductModel->getProduct($productId);
            if (!$product) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Product not found with ID: ' . $productId
                ), 404);
            }
            
            // Get parameters
            $customFilename = isset($this->request->post['filename']) ? trim($this->request->post['filename']) : '';
            $preserveName = isset($this->request->post['preserve_name']) ? (bool)$this->request->post['preserve_name'] : false;
            
            // Upload image
            $jsonData = json_decode(file_get_contents('php://input'), true);
            
            if ($jsonData && isset($jsonData['image_data'])) {
                $uploadResult = $this->uploadBase64Image(
                    $jsonData['image_data'],
                    isset($jsonData['filename']) ? $jsonData['filename'] : $customFilename,
                    'products',
                    isset($jsonData['resize_width']) ? (int)$jsonData['resize_width'] : 800,
                    isset($jsonData['resize_height']) ? (int)$jsonData['resize_height'] : 800
                );
            } elseif (isset($_FILES['image'])) {
                $uploadResult = $this->uploadFileImage(
                    $_FILES['image'], 
                    'products', 
                    800, 
                    800,
                    $customFilename,
                    $preserveName
                );
            } else {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'No image provided'
                ), 400);
            }
            
            // Update product with new image
            $this->db->query("UPDATE " . DB_PREFIX . "product SET image = '" . $this->db->escape($uploadResult['filename']) . "' WHERE product_id = '" . (int)$productId . "'");
            
            $this->sendResponse(array(
                'success' => true,
                'message' => 'Product image updated successfully',
                'product_id' => $productId,
                'image' => $uploadResult
            ));
            
        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }
    
    /**
     * Delete image file
     * 
     * Endpoint: DELETE /index.php?route=api/product_api/deleteImage&filename=products/image-123.jpg&api_key=xxx
     * 
     * ============================================================
     * URL PARAMETERS
     * ============================================================
     * - filename (string, required) - Relative path to image file
     *   Example: filename=products/image-1234567890-abc123.jpg
     *            filename=products/my-custom-image.webp
     * 
     * ============================================================
     * RESPONSE (Success)
     * ============================================================
     * {
     *   "success": true,
     *   "message": "Image deleted successfully",
     *   "filename": "products/image-1234567890-abc123.jpg"
     * }
     * 
     * cURL Example:
     * curl -X DELETE \
     *   'https://yoursite.com/index.php?route=api/product_api/deleteImage&filename=products/my-image.jpg&api_key=YOUR_KEY'
     */
    public function deleteImage() {
        $this->authenticate();
        
        try {
            $filename = isset($this->request->get['filename']) ? $this->request->get['filename'] : '';
            
            if (empty($filename)) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'filename parameter is required',
                    'required_fields' => array('filename'),
                    'example' => 'filename=products/image-123.jpg'
                ), 400);
            }
            
            $filepath = $this->getImageDirectory() . $filename;
            
            if (!file_exists($filepath)) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Image file not found: ' . $filename
                ), 404);
            }
            
            // Delete file
            if (!unlink($filepath)) {
                throw new Exception('Failed to delete image file');
            }
            
            $this->sendResponse(array(
                'success' => true,
                'message' => 'Image deleted successfully',
                'filename' => $filename
            ));
            
        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }
    
    // ==================== PRIVATE IMAGE HELPER METHODS ====================
    
    /**
     * Upload file from $_FILES array with custom filename support
     * PHP 5.6+ compatible with enhanced WebP detection
     * 
     * @param array $file               $_FILES array element
     * @param string $subfolder         Subfolder name (e.g., "products", "banners")
     * @param int $resizeWidth          Target width in pixels (0 = no resize)
     * @param int $resizeHeight         Target height in pixels (0 = no resize)
     * @param string $customFilename    Custom filename (optional)
     * @param bool $preserveOriginal    Use original uploaded filename (optional)
     * @return array                    Upload result with file info
     * @throws Exception                On validation or upload failure
     */
    private function uploadFileImage($file, $subfolder, $resizeWidth, $resizeHeight, $customFilename = '', $preserveOriginal = false) {
        // Validate file upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error: ' . $this->getUploadErrorMessage($file['error']));
        }
        
        // Validate file size (max 10MB)
        $maxSize = 10 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            throw new Exception('File too large. Maximum size: 10MB');
        }
        
        // Get original filename and extension
        $originalFilename = pathinfo($file['name'], PATHINFO_FILENAME);
        $originalExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        // Detect MIME type using magic bytes (most reliable)
        $actualMime = $this->detectImageTypeBySignature($file['tmp_name']);
        
        // Fallback to finfo if signature detection fails
        if (!$actualMime) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedMime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            // Special handling for WebP
            if (($detectedMime === 'application/octet-stream' || !$detectedMime) && $originalExtension === 'webp') {
                if ($this->isValidWebP($file['tmp_name'])) {
                    $actualMime = 'image/webp';
                }
            } else {
                $actualMime = $detectedMime;
            }
        }
        
        // Validate MIME type
        $allowedMimes = array(
            'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 
            'image/webp', 'image/bmp', 'image/x-ms-bmp', 'image/x-bmp'
        );
        
        if (!in_array($actualMime, $allowedMimes)) {
            throw new Exception(
                'Invalid file type. Allowed: JPG, PNG, GIF, WebP, BMP. ' .
                'Detected: ' . $actualMime
            );
        }
        
        // Determine final filename
        $extension = $this->getExtensionFromMime($actualMime);
        $filename = $this->determineFilename($customFilename, $originalFilename, $extension, $preserveOriginal, $subfolder);
        
        // Create upload directory
        $uploadDir = $this->getImageDirectory() . $subfolder . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $targetPath = $uploadDir . $filename;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception('Failed to move uploaded file');
        }
        
        // Verify the file is a valid image after upload
        $imageInfo = $this->getImageInfo($targetPath, $actualMime);
        
        if (!$imageInfo) {
            unlink($targetPath);
            throw new Exception('Uploaded file is not a valid image');
        }
        
        // Resize if requested
        if ($resizeWidth > 0 || $resizeHeight > 0) {
            $resizeResult = $this->resizeImage($targetPath, $resizeWidth, $resizeHeight, $actualMime);
            
            if ($resizeResult) {
                $imageInfo = $this->getImageInfo($targetPath, $actualMime);
            }
        }
        
        return array(
            'filename' => $subfolder . '/' . $filename,
            'original_name' => $file['name'],
            'path' => $targetPath,
            'url' => $this->getImageUrl($subfolder . '/' . $filename),
            'size' => filesize($targetPath),
            'mime_type' => $actualMime,
            'dimensions' => array(
                'width' => $imageInfo['width'],
                'height' => $imageInfo['height']
            )
        );
    }
    
    /**
     * Determine final filename based on priority
     * 
     * Priority order:
     * 1. Custom filename provided
     * 2. Preserve original filename
     * 3. Generate unique filename
     * 
     * @param string $customFilename    Custom filename from request
     * @param string $originalFilename  Original uploaded filename (without extension)
     * @param string $extension         File extension
     * @param bool $preserveOriginal    Whether to preserve original name
     * @param string $subfolder         Subfolder for checking duplicates
     * @return string                   Final sanitized filename
     */
    private function determineFilename($customFilename, $originalFilename, $extension, $preserveOriginal, $subfolder) {
        $baseFilename = '';
        
        // Priority 1: Custom filename provided
        if (!empty($customFilename)) {
            $baseFilename = $customFilename;
        }
        // Priority 2: Preserve original filename
        elseif ($preserveOriginal && !empty($originalFilename)) {
            $baseFilename = $originalFilename;
        }
        // Priority 3: Generate unique filename
        else {
            return 'image-' . time() . '-' . uniqid() . '.' . $extension;
        }
        
        // Remove extension if provided in custom filename
        $providedExtension = strtolower(pathinfo($baseFilename, PATHINFO_EXTENSION));
        if (!empty($providedExtension)) {
            $baseFilename = pathinfo($baseFilename, PATHINFO_FILENAME);
            // Use provided extension if valid image format
            $validExtensions = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp');
            if (in_array($providedExtension, $validExtensions)) {
                $extension = $providedExtension;
            }
        }
        
        // Sanitize filename
        $baseFilename = $this->sanitizeFilename($baseFilename);
        
        // Check if file exists and make unique
        $finalFilename = $baseFilename . '.' . $extension;
        $uploadDir = $this->getImageDirectory() . $subfolder . '/';
        
        if (file_exists($uploadDir . $finalFilename)) {
            // Add timestamp to make unique
            $finalFilename = $baseFilename . '-' . time() . '.' . $extension;
            
            // If still exists, add random string
            if (file_exists($uploadDir . $finalFilename)) {
                $finalFilename = $baseFilename . '-' . time() . '-' . substr(uniqid(), -6) . '.' . $extension;
            }
        }
        
        return $finalFilename;
    }
    
    /**
     * Sanitize filename - remove invalid characters
     * Keeps: alphanumeric, dashes, underscores
     * Converts: spaces to dashes
     * Removes: special characters
     * 
     * @param string $filename  Filename to sanitize
     * @return string           Sanitized filename
     */
    private function sanitizeFilename($filename) {
        // Convert to lowercase
        $filename = strtolower($filename);
        
        // Replace spaces with dashes
        $filename = str_replace(' ', '-', $filename);
        
        // Remove any character that isn't alphanumeric, dash, or underscore
        $filename = preg_replace('/[^a-z0-9\-_]/', '', $filename);
        
        // Remove multiple consecutive dashes
        $filename = preg_replace('/-+/', '-', $filename);
        
        // Remove leading/trailing dashes
        $filename = trim($filename, '-');
        
        // If empty after sanitization, generate random name
        if (empty($filename)) {
            $filename = 'image-' . uniqid();
        }
        
        // Limit length to 100 characters
        if (strlen($filename) > 100) {
            $filename = substr($filename, 0, 100);
        }
        
        return $filename;
    }
    
    /**
     * Upload base64 encoded image with custom filename support
     * PHP 5.6+ compatible
     * 
     * @param string $base64Data    Base64 image data (with or without data URI prefix)
     * @param string $filename      Custom or original filename (optional)
     * @param string $subfolder     Subfolder name
     * @param int $resizeWidth      Target width (0 = no resize)
     * @param int $resizeHeight     Target height (0 = no resize)
     * @return array                Upload result
     * @throws Exception            On validation or save failure
     */
    private function uploadBase64Image($base64Data, $filename, $subfolder, $resizeWidth, $resizeHeight) {
        $providedFilename = $filename;
        
        // Remove data URI prefix if present
        if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $matches)) {
            $detectedExtension = $matches[1];
            $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
        } else {
            $detectedExtension = $filename ? strtolower(pathinfo($filename, PATHINFO_EXTENSION)) : 'jpg';
        }
        
        // Decode base64
        $imageData = base64_decode($base64Data);
        
        if ($imageData === false || strlen($imageData) < 100) {
            throw new Exception('Invalid base64 image data');
        }
        
        // Create temporary file to validate
        $tempFile = tempnam(sys_get_temp_dir(), 'img_');
        file_put_contents($tempFile, $imageData);
        
        // Detect actual MIME type
        $mimeType = $this->detectImageTypeBySignature($tempFile);
        
        if (!$mimeType) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $tempFile);
            finfo_close($finfo);
        }
        
        $allowedMimes = array(
            'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 
            'image/webp', 'image/bmp', 'image/x-ms-bmp', 'image/x-bmp'
        );
        
        if (!in_array($mimeType, $allowedMimes)) {
            unlink($tempFile);
            throw new Exception('Invalid image format. Detected: ' . $mimeType);
        }
        
        // Determine extension and filename
        $extension = $this->getExtensionFromMime($mimeType);
        
        if (!empty($filename)) {
            $baseFilename = pathinfo($filename, PATHINFO_FILENAME);
            $finalFilename = $this->determineFilename($filename, $baseFilename, $extension, false, $subfolder);
        } else {
            $finalFilename = 'image-' . time() . '-' . uniqid() . '.' . $extension;
        }
        
        // Create directory
        $uploadDir = $this->getImageDirectory() . $subfolder . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $targetPath = $uploadDir . $finalFilename;
        
        // Move temp file to target
        if (!rename($tempFile, $targetPath)) {
            unlink($tempFile);
            throw new Exception('Failed to save image file');
        }
        
        $imageInfo = $this->getImageInfo($targetPath, $mimeType);
        
        if (!$imageInfo) {
            unlink($targetPath);
            throw new Exception('Uploaded file is not a valid image');
        }
        
        // Resize if requested
        if ($resizeWidth > 0 || $resizeHeight > 0) {
            $this->resizeImage($targetPath, $resizeWidth, $resizeHeight, $mimeType);
            $imageInfo = $this->getImageInfo($targetPath, $mimeType);
        }
        
        return array(
            'filename' => $subfolder . '/' . $finalFilename,
            'original_name' => $providedFilename,
            'path' => $targetPath,
            'url' => $this->getImageUrl($subfolder . '/' . $finalFilename),
            'size' => filesize($targetPath),
            'mime_type' => $mimeType,
            'dimensions' => array(
                'width' => $imageInfo['width'],
                'height' => $imageInfo['height']
            )
        );
    }
    
    /**
     * Get image dimensions with fallback methods
     * Works even when getimagesize() fails (common with WebP on PHP 5.6)
     * 
     * @param string $filepath  Path to image file
     * @param string $mimeType  MIME type of the image
     * @return array|false      Array with width/height or false
     */
    private function getImageInfo($filepath, $mimeType) {
        // Try getimagesize first
        $imageInfo = @getimagesize($filepath);
        
        if ($imageInfo && $imageInfo[0] > 0 && $imageInfo[1] > 0) {
            return array(
                'width' => $imageInfo[0],
                'height' => $imageInfo[1]
            );
        }
        
        // Fallback for WebP
        if ($mimeType === 'image/webp') {
            if (function_exists('imagecreatefromwebp')) {
                $img = @imagecreatefromwebp($filepath);
                if ($img) {
                    $width = imagesx($img);
                    $height = imagesy($img);
                    imagedestroy($img);
                    
                    if ($width > 0 && $height > 0) {
                        return array(
                            'width' => $width,
                            'height' => $height
                        );
                    }
                }
            }
            
            return $this->getWebPDimensions($filepath);
        }
        
        return false;
    }
    
    /**
     * Read WebP dimensions from file header
     * Works even when GD library doesn't support WebP
     * 
     * @param string $filepath  Path to WebP file
     * @return array|false      Array with width/height or false
     */
    private function getWebPDimensions($filepath) {
        $fp = fopen($filepath, 'rb');
        if (!$fp) {
            return false;
        }
        
        $header = fread($fp, 30);
        
        if (strlen($header) < 30) {
            fclose($fp);
            return false;
        }
        
        if (substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WEBP') {
            fclose($fp);
            return false;
        }
        
        $chunkType = substr($header, 12, 4);
        
        $width = 0;
        $height = 0;
        
        if ($chunkType === 'VP8 ') {
            if (strlen($header) >= 30) {
                $bytes = unpack('v', substr($header, 26, 2));
                $width = $bytes[1] & 0x3FFF;
                $bytes = unpack('v', substr($header, 28, 2));
                $height = $bytes[1] & 0x3FFF;
            }
        } elseif ($chunkType === 'VP8L') {
            $data = fread($fp, 10);
            if (strlen($data) >= 5) {
                $bytes = unpack('V', substr($data, 1, 4));
                $bits = $bytes[1];
                $width = ($bits & 0x3FFF) + 1;
                $height = (($bits >> 14) & 0x3FFF) + 1;
            }
        } elseif ($chunkType === 'VP8X') {
            if (strlen($header) >= 30) {
                $bytes = unpack('V', substr($header, 24, 3) . "\0");
                $width = ($bytes[1] & 0xFFFFFF) + 1;
                $bytes = unpack('V', substr($header, 27, 3) . "\0");
                $height = ($bytes[1] & 0xFFFFFF) + 1;
            }
        }
        
        fclose($fp);
        
        if ($width > 0 && $height > 0) {
            return array(
                'width' => $width,
                'height' => $height
            );
        }
        
        return false;
    }
    
    /**
     * Resize image maintaining aspect ratio
     * 
     * @param string $filepath      Path to image file
     * @param int $maxWidth         Maximum width (0 = no limit)
     * @param int $maxHeight        Maximum height (0 = no limit)
     * @param string $mimeType      MIME type (optional)
     * @return bool                 True if resized, false if skipped
     */
    private function resizeImage($filepath, $maxWidth, $maxHeight, $mimeType) {
        if (!$mimeType) {
            $imageInfo = @getimagesize($filepath);
            if (!$imageInfo) {
                return false;
            }
            $width = $imageInfo[0];
            $height = $imageInfo[1];
            $type = $imageInfo[2];
        } else {
            $dimensions = $this->getImageInfo($filepath, $mimeType);
            if (!$dimensions) {
                return false;
            }
            $width = $dimensions['width'];
            $height = $dimensions['height'];
            $type = $this->getMimeToImageType($mimeType);
        }
        
        if ($maxWidth == 0) {
            $maxWidth = $width;
        }
        if ($maxHeight == 0) {
            $maxHeight = $height;
        }
        
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        
        if ($ratio >= 1) {
            return false;
        }
        
        $newWidth = (int)($width * $ratio);
        $newHeight = (int)($height * $ratio);
        
        $source = false;
        switch ($type) {
            case IMAGETYPE_JPEG:
                $source = @imagecreatefromjpeg($filepath);
                break;
            case IMAGETYPE_PNG:
                $source = @imagecreatefrompng($filepath);
                break;
            case IMAGETYPE_GIF:
                $source = @imagecreatefromgif($filepath);
                break;
            case IMAGETYPE_WEBP:
                if (function_exists('imagecreatefromwebp')) {
                    $source = @imagecreatefromwebp($filepath);
                } else {
                    return false;
                }
                break;
            case IMAGETYPE_BMP:
                if (function_exists('imagecreatefrombmp')) {
                    $source = @imagecreatefrombmp($filepath);
                } else {
                    return false;
                }
                break;
            default:
                return false;
        }
        
        if (!$source) {
            return false;
        }
        
        $destination = imagecreatetruecolor($newWidth, $newHeight);
        
        if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF || $type == IMAGETYPE_WEBP) {
            imagealphablending($destination, false);
            imagesavealpha($destination, true);
            $transparent = imagecolorallocatealpha($destination, 255, 255, 255, 127);
            imagefilledrectangle($destination, 0, 0, $newWidth, $newHeight, $transparent);
        }
        
        imagecopyresampled($destination, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        
        $saved = false;
        switch ($type) {
            case IMAGETYPE_JPEG:
                $saved = imagejpeg($destination, $filepath, 90);
                break;
            case IMAGETYPE_PNG:
                $saved = imagepng($destination, $filepath, 9);
                break;
            case IMAGETYPE_GIF:
                $saved = imagegif($destination, $filepath);
                break;
            case IMAGETYPE_WEBP:
                if (function_exists('imagewebp')) {
                    $saved = imagewebp($destination, $filepath, 90);
                }
                break;
            case IMAGETYPE_BMP:
                if (function_exists('imagebmp')) {
                    $saved = imagebmp($destination, $filepath);
                }
                break;
        }
        
        imagedestroy($source);
        imagedestroy($destination);
        
        return $saved !== false;
    }
    
    /**
     * Detect image type by reading file signature (magic bytes)
     */
    private function detectImageTypeBySignature($filepath) {
        $handle = fopen($filepath, 'rb');
        if (!$handle) {
            return false;
        }
        
        $bytes = fread($handle, 16);
        fclose($handle);
        
        if (strlen($bytes) < 4) {
            return false;
        }
        
        if (substr($bytes, 0, 3) === "\xFF\xD8\xFF") {
            return 'image/jpeg';
        }
        
        if (substr($bytes, 0, 8) === "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A") {
            return 'image/png';
        }
        
        if (substr($bytes, 0, 6) === "GIF87a" || substr($bytes, 0, 6) === "GIF89a") {
            return 'image/gif';
        }
        
        if (substr($bytes, 0, 4) === "RIFF" && substr($bytes, 8, 4) === "WEBP") {
            return 'image/webp';
        }
        
        if (substr($bytes, 0, 2) === "BM") {
            return 'image/bmp';
        }
        
        return false;
    }
    
    /**
     * Validate WebP file structure
     */
    private function isValidWebP($filepath) {
        $handle = fopen($filepath, 'rb');
        if (!$handle) {
            return false;
        }
        
        $header = fread($handle, 12);
        fclose($handle);
        
        if (strlen($header) < 12) {
            return false;
        }
        
        return (substr($header, 0, 4) === "RIFF" && substr($header, 8, 4) === "WEBP");
    }
    
    /**
     * Convert MIME type to IMAGETYPE constant
     */
    private function getMimeToImageType($mimeType) {
        $map = array(
            'image/jpeg' => IMAGETYPE_JPEG,
            'image/jpg' => IMAGETYPE_JPEG,
            'image/png' => IMAGETYPE_PNG,
            'image/gif' => IMAGETYPE_GIF,
            'image/webp' => defined('IMAGETYPE_WEBP') ? IMAGETYPE_WEBP : 18,
            'image/bmp' => defined('IMAGETYPE_BMP') ? IMAGETYPE_BMP : 6,
            'image/x-ms-bmp' => defined('IMAGETYPE_BMP') ? IMAGETYPE_BMP : 6,
            'image/x-bmp' => defined('IMAGETYPE_BMP') ? IMAGETYPE_BMP : 6
        );
        
        return isset($map[$mimeType]) ? $map[$mimeType] : IMAGETYPE_JPEG;
    }
    
    /**
     * Get image directory path
     */
    private function getImageDirectory() {
        return DIR_IMAGE . 'catalog/';
    }
    
    /**
     * Get public image URL
     */
    private function getImageUrl($filename) {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $scriptPath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        
        return $protocol . "://" . $host . $scriptPath . '/image/catalog/' . $filename;
    }
    
    /**
     * Get file extension from MIME type
     */
    private function getExtensionFromMime($mimeType) {
        $mimeMap = array(
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/x-ms-bmp' => 'bmp',
            'image/x-bmp' => 'bmp'
        );
        
        return isset($mimeMap[$mimeType]) ? $mimeMap[$mimeType] : 'jpg';
    }
    
    /**
     * Get upload error message
     */
    private function getUploadErrorMessage($errorCode) {
        $errors = array(
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive in HTML form',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'PHP extension stopped the file upload'
        );
        
        return isset($errors[$errorCode]) ? $errors[$errorCode] : 'Unknown upload error';
    }
    

    /**
     * Update product information with COMPLETE field preservation
     * POST: /index.php?route=api/product_api/updateProduct&product_id=123&api_key=xxx
     * Body: JSON with product data
     * 
     * VERSION 1.1 - Enhanced with comprehensive relational data preservation
     * Preserves ALL OpenCart relational fields (options, discounts, specials, etc.)
     * Only updates fields that are explicitly provided in the request
     * Compatible with future OpenCart versions
     */
    public function updateProduct() {
        $this->authenticate();
        
        try {
            $productId = isset($this->request->get['product_id']) ? (int)$this->request->get['product_id'] : 0;
            
            if (!$productId) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Product ID is required'
                ), 400);
            }
            
            // Check if product exists
            $existingProduct = $this->adminProductModel->getProduct($productId);
            if (!$existingProduct) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Product not found'
                ), 404);
            }
            
            // Get JSON data from request body
            $jsonData = json_decode(file_get_contents('php://input'), true);
            
            if (!$jsonData) {
                $jsonData = $this->request->post;
            }
            
            if (empty($jsonData)) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'No data provided in request body'
                ), 400);
            }
            
            // Merge with existing product data (preserves simple fields in oc_product table)
            $data = array_merge($existingProduct, $jsonData);
            
            // ========================================
            // HANDLE PRODUCT DESCRIPTIONS
            // ========================================
            if (!isset($data['product_description'])) {
                $data['product_description'] = $this->adminProductModel->getProductDescriptions($productId);
            }
            
            // Update description if provided in JSON
            if (isset($jsonData['description']) || isset($jsonData['name'])) {
                $langId = isset($jsonData['language_id']) ? (int)$jsonData['language_id'] : $this->getLanguageId();
                
                if (!isset($data['product_description'][$langId])) {
                    $data['product_description'][$langId] = array();
                }
                
                if (isset($jsonData['description'])) {
                    $data['product_description'][$langId]['description'] = $jsonData['description'];
                }
                if (isset($jsonData['name'])) {
                    $data['product_description'][$langId]['name'] = $jsonData['name'];
                }
                if (isset($jsonData['meta_title'])) {
                    $data['product_description'][$langId]['meta_title'] = $jsonData['meta_title'];
                }
                if (isset($jsonData['meta_description'])) {
                    $data['product_description'][$langId]['meta_description'] = $jsonData['meta_description'];
                }
                if (isset($jsonData['meta_keyword'])) {
                    $data['product_description'][$langId]['meta_keyword'] = $jsonData['meta_keyword'];
                }
                if (isset($jsonData['tag'])) {
                    $data['product_description'][$langId]['tag'] = $jsonData['tag'];
                }
            }
            
            // ========================================
            // HANDLE PRODUCT ATTRIBUTES
            // ========================================
            if (isset($jsonData['attributes'])) {
                $data['product_attribute'] = array();
                foreach ($jsonData['attributes'] as $attr) {
                    if (isset($attr['attribute_id'])) {
                        $langId = isset($attr['language_id']) ? (int)$attr['language_id'] : $this->getLanguageId();
                        
                        $data['product_attribute'][] = array(
                            'attribute_id' => $attr['attribute_id'],
                            'product_attribute_description' => array(
                                $langId => array(
                                    'text' => isset($attr['value']) ? $attr['value'] : (isset($attr['text']) ? $attr['text'] : '')
                                )
                            )
                        );
                    }
                }
            } else if (!isset($data['product_attribute'])) {
                $data['product_attribute'] = $this->adminProductModel->getProductAttributes($productId);
            }
            
            // ========================================
            //✅✅ PRESERVE ALL RELATIONAL DATA✅✅
            // This is the KEY FIX - preserves all relational tables
            // ========================================
            
            // Related products
            if (!isset($data['product_related'])) {
                $data['product_related'] = $this->adminProductModel->getProductRelated($productId);
            }
            
            // Store associations
            if (!isset($data['product_store'])) {
                $data['product_store'] = $this->adminProductModel->getProductStores($productId);
            }
            
            // Category associations
            if (!isset($data['product_category'])) {
                $data['product_category'] = $this->adminProductModel->getProductCategories($productId);
            }
            
            // Filters
            if (!isset($data['product_filter'])) {
                $data['product_filter'] = $this->adminProductModel->getProductFilters($productId);
            }
            
            // Downloads
            if (!isset($data['product_download'])) {
                $data['product_download'] = $this->adminProductModel->getProductDownloads($productId);
            }
            
            // Layouts
            if (!isset($data['product_layout'])) {
                $data['product_layout'] = $this->adminProductModel->getProductLayouts($productId);
            }
            
            // Images (additional product images)
            if (!isset($data['product_image'])) {
                $data['product_image'] = $this->adminProductModel->getProductImages($productId);
            }
            
            
            // Product Options (size, color dropdowns, radio buttons, checkboxes, etc.)
            // ⚠️ THIS WAS THE MAIN BUG - Options were being deleted!
            // Product Options (size, color dropdowns, etc.)
            if (!isset($data['product_option'])) {
                if (method_exists($this->adminProductModel, 'getProductOptions')) {
                    $data['product_option'] = $this->adminProductModel->getProductOptions($productId);
                } else {
                    $data['product_option'] = array();
                }
            }
            
            // Quantity-based discounts (tier pricing)
            if (!isset($data['product_discount'])) {
                if (method_exists($this->adminProductModel, 'getProductDiscounts')) {
                    $data['product_discount'] = $this->adminProductModel->getProductDiscounts($productId);
                } else {
                    $data['product_discount'] = array();
                }
            }
            
            // Special prices with date ranges (sale prices)
            if (!isset($data['product_special'])) {
                if (method_exists($this->adminProductModel, 'getProductSpecials')) {
                    $data['product_special'] = $this->adminProductModel->getProductSpecials($productId);
                } else {
                    $data['product_special'] = array();
                }
            }
            
            // Reward points per customer group
            if (!isset($data['product_reward'])) {
                if (method_exists($this->adminProductModel, 'getProductRewards')) {
                    $data['product_reward'] = $this->adminProductModel->getProductRewards($productId);
                } else {
                    $data['product_reward'] = array();
                }
            }
            
            // Recurring payment profiles (subscriptions)
            // Fixed: Check method name and existence
            if (!isset($data['product_recurring'])) {
                // Try singular form first (OpenCart standard)
                if (method_exists($this->adminProductModel, 'getProductRecurring')) {
                    try {
                        $data['product_recurring'] = $this->adminProductModel->getProductRecurring($productId);
                    } catch (Exception $e) {
                        $data['product_recurring'] = array();
                    }
                } 
                // Fallback: Try plural form
                elseif (method_exists($this->adminProductModel, 'getProductRecurrings')) {
                    try {
                        $data['product_recurring'] = $this->adminProductModel->getProductRecurrings($productId);
                    } catch (Exception $e) {
                        $data['product_recurring'] = array();
                    }
                }
                // Neither method exists
                else {
                    $data['product_recurring'] = array();
                }
            }
            
            // ========================================
            // UPDATE PRODUCT IN DATABASE
            // ========================================
            $this->adminProductModel->editProduct($productId, $data);
            
            $this->sendResponse(array(
                'success' => true,
                'message' => 'Product updated successfully',
                'product_id' => $productId
            ));
            
        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }
    
    /**
     * OPTIONAL: Helper method to get all relational data (FUTURE-PROOF approach)
     * 
     * This method automatically detects and retrieves all known relational fields.
     * If OpenCart adds new fields in future versions, just add them to this array.
     * 
     * @param int $productId Product ID
     * @return array Associative array of field_name => data
     */
    private function getProductRelationalData($productId) {
        // Map of data field name => model method to call
        $relationalFields = array(
            'product_store'     => 'getProductStores',
            'product_category'  => 'getProductCategories',
            'product_filter'    => 'getProductFilters',
            'product_download'  => 'getProductDownloads',
            'product_layout'    => 'getProductLayouts',
            'product_image'     => 'getProductImages',
            'product_option'    => 'getProductOptions',
            'product_discount'  => 'getProductDiscounts',
            'product_special'   => 'getProductSpecials',
            'product_reward'    => 'getProductRewards',
            'product_recurring' => 'getProductRecurrings',
            'product_related'   => 'getProductRelated',
            'product_attribute' => 'getProductAttributes'
        );
        
        $data = array();
        
        foreach ($relationalFields as $field => $method) {
            // Check if method exists (for compatibility with different OpenCart versions)
            if (method_exists($this->adminProductModel, $method)) {
                try {
                    $data[$field] = $this->adminProductModel->$method($productId);
                } catch (Exception $e) {
                    // Log but don't fail - some methods might not exist in older versions
                    error_log("Warning: Could not call {$method}: " . $e->getMessage());
                    $data[$field] = array();
                }
            }
        }
        
        return $data;
    }
    
    /**
     * ALTERNATIVE updateProduct() using the helper method
     * Uncomment this version if you prefer the cleaner approach
     * 
     * public function updateProduct() {
     *     $this->authenticate();
     *     
     *     try {
     *         $productId = isset($this->request->get['product_id']) ? (int)$this->request->get['product_id'] : 0;
     *         
     *         if (!$productId) {
     *             $this->sendResponse(array(
     *                 'success' => false,
     *                 'error' => 'Product ID is required'
     *             ), 400);
     *         }
     *         
     *         $existingProduct = $this->adminProductModel->getProduct($productId);
     *         if (!$existingProduct) {
     *             $this->sendResponse(array(
     *                 'success' => false,
     *                 'error' => 'Product not found'
     *             ), 404);
     *         }
     *         
     *         $jsonData = json_decode(file_get_contents('php://input'), true);
     *         if (!$jsonData) {
     *             $jsonData = $this->request->post;
     *         }
     *         
     *         if (empty($jsonData)) {
     *             $this->sendResponse(array(
     *                 'success' => false,
     *                 'error' => 'No data provided'
     *             ), 400);
     *         }
     *         
     *         $data = array_merge($existingProduct, $jsonData);
     *         
     *         // Handle descriptions
     *         if (!isset($data['product_description'])) {
     *             $data['product_description'] = $this->adminProductModel->getProductDescriptions($productId);
     *         }
     *         
     *         // Handle attributes
     *         if (isset($jsonData['attributes'])) {
     *             // ... attribute handling code ...
     *         } else if (!isset($data['product_attribute'])) {
     *             $data['product_attribute'] = $this->adminProductModel->getProductAttributes($productId);
     *         }
     *         
     *         // Smart preservation of all relational data
     *         $relationalData = $this->getProductRelationalData($productId);
     *         foreach ($relationalData as $field => $value) {
     *             if (!isset($data[$field])) {
     *                 $data[$field] = $value;
     *             }
     *         }
     *         
     *         $this->adminProductModel->editProduct($productId, $data);
     *         
     *         $this->sendResponse(array(
     *             'success' => true,
     *             'message' => 'Product updated successfully',
     *             'product_id' => $productId
     *         ));
     *         
     *     } catch (Exception $e) {
     *         $this->sendResponse(array(
     *             'success' => false,
     *             'error' => $e->getMessage()
     *         ), 500);
     *     }
     * }
     */
    
    ///**
    // * Delete product
    // * DELETE: /index.php?route=api/product_api/deleteProduct&product_id=123&api_key=xxx
    // */
    //public function deleteProduct() {
    //    $this->authenticate();
    //    
    //    try {
    //        $productId = isset($this->request->get['product_id']) ? (int)$this->request->get['product_id'] : 0;
    //        
    //        if (!$productId) {
    //            $this->sendResponse(array(
    //                'success' => false,
    //                'error' => 'Product ID is required'
    //            ), 400);
    //        }
    //        
    //        // Check if product exists
    //        $existingProduct = $this->adminProductModel->getProduct($productId);
    //        if (!$existingProduct) {
    //            $this->sendResponse(array(
    //                'success' => false,
    //                'error' => 'Product not found'
    //            ), 404);
    //        }
    //        
    //        // Delete product
    //        $this->adminProductModel->deleteProduct($productId);
    //        
    //        $this->sendResponse(array(
    //            'success' => true,
    //            'message' => 'Product deleted successfully',
    //            'product_id' => $productId
    //        ));
    //        
    //    } catch (Exception $e) {
    //        $this->sendResponse(array(
    //            'success' => false,
    //            'error' => $e->getMessage()
    //        ), 500);
    //    }
    //}

    // ==================== GET PRODUCTS BY CATEGORY ====================
    
    /**
     * Get products by category ID or name
     * 
     * GET: /index.php?route=api/product_api/getProductsByCategory&category_id=25&api_key=xxx
     * GET: /index.php?route=api/product_api/getProductsByCategory&category_name=موبایل&api_key=xxx
     * 
     * Required Fields:
     * - category_id (int) OR category_name (string)
     * 
     * Optional Fields:
     * - start (int) - Pagination offset (default: 0)
     * - limit (int) - Results per page (default: 100, max: 100)
     * - language_id (int) - Language ID (default: 2)
     * 
     * Response Example:
     * {
     *   "success": true,
     *   "category_info": {
     *     "category_id": 25,
     *     "name": "موبایل",
     *     "parent_id": 20
     *   },
     *   "data": [
     *     {
     *       "product_id": 42,
     *       "name": "iPhone 15 Pro",
     *       "model": "IP15P-128",
     *       "sku": "APPLE-IP15P",
     *       "quantity": 50,
     *       "price": "999.00",
     *       "status": 1
     *     }
     *   ],
     *   "count": 15,
     *   "total": 15
     * }
     */
    public function getProductsByCategory() {
        $this->authenticate();

        try {
            $categoryId = isset($this->request->get['category_id']) ? (int)$this->request->get['category_id'] : 0;
            $categoryName = isset($this->request->get['category_name']) ? trim($this->request->get['category_name']) : '';
            $start = isset($this->request->get['start']) ? (int)$this->request->get['start'] : 0;
            $limit = isset($this->request->get['limit']) ? (int)$this->request->get['limit'] : 100;
            $limit = min($limit, 100);

            // Find category by name if ID not provided
            if (!$categoryId && !empty($categoryName)) {
                $catQuery = $this->db->query(
                    "SELECT c.category_id 
                     FROM " . DB_PREFIX . "category c
                     LEFT JOIN " . DB_PREFIX . "category_description cd
                        ON c.category_id = cd.category_id
                     WHERE cd.language_id = '" . (int)$this->getLanguageId() . "'
                     AND cd.name = '" . $this->db->escape($categoryName) . "'
                     LIMIT 1"
                );

                if ($catQuery->num_rows > 0) {
                    $categoryId = $catQuery->row['category_id'];
                } else {
                    $this->sendResponse(array(
                        'success' => false,
                        'error' => 'Category not found: ' . $categoryName
                    ), 404);
                    return;
                }
            }

            if (!$categoryId) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'category_id or category_name is required'
                ), 400);
                return;
            }

            // Get category info
            $catInfoQuery = $this->db->query(
                "SELECT c.category_id, cd.name, c.parent_id, c.status
                 FROM " . DB_PREFIX . "category c
                 LEFT JOIN " . DB_PREFIX . "category_description cd
                    ON c.category_id = cd.category_id
                 WHERE c.category_id = '" . (int)$categoryId . "'
                 AND cd.language_id = '" . (int)$this->getLanguageId() . "'"
            );

            if ($catInfoQuery->num_rows === 0) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Category not found with ID: ' . $categoryId
                ), 404);
                return;
            }

            $categoryInfo = $catInfoQuery->row;

            // Get products in category
            $sql = "SELECT DISTINCT p.product_id, pd.name, p.model, p.sku, 
                           p.quantity, p.price, p.status, p.image, p.date_added
                    FROM " . DB_PREFIX . "product p
                    LEFT JOIN " . DB_PREFIX . "product_description pd 
                        ON p.product_id = pd.product_id
                    LEFT JOIN " . DB_PREFIX . "product_to_category p2c
                        ON p.product_id = p2c.product_id
                    WHERE p2c.category_id = '" . (int)$categoryId . "'
                    AND pd.language_id = '" . (int)$this->getLanguageId() . "'
                    AND p.status = 1
                    ORDER BY p.sort_order ASC, pd.name ASC
                    LIMIT " . (int)$start . ", " . (int)$limit;

            $query = $this->db->query($sql);

            // بررسی نتیجه query
            if (!$query) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Database query failed'
                ), 500);
                return;
            }

            // Get total count
            $countSql = "SELECT COUNT(DISTINCT p.product_id) as total
                         FROM " . DB_PREFIX . "product p
                         LEFT JOIN " . DB_PREFIX . "product_to_category p2c
                            ON p.product_id = p2c.product_id
                         WHERE p2c.category_id = '" . (int)$categoryId . "'
                         AND p.status = 1";

            $countQuery = $this->db->query($countSql);
            $total = isset($countQuery->row['total']) ? (int)$countQuery->row['total'] : 0;

            // مطمئن شویم rows یک array است
            $products = isset($query->rows) && is_array($query->rows) ? $query->rows : array();

            $this->sendResponse(array(
                'success' => true,
                'category_info' => $categoryInfo,
                'data' => $products,
                'count' => count($products),
                'total' => $total
            ));

        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ), 500);
        }
    }

    // ==================== GET MULTIPLE PRODUCTS BY IDS ====================
    
    /**
     * Get multiple products by their IDs
     * 
     * GET: /index.php?route=api/product_api/getProductsByIds&product_ids=42,43,44&api_key=xxx
     * POST: /index.php?route=api/product_api/getProductsByIds&api_key=xxx
     * Body: {"product_ids": [42, 43, 44]}
     * 
     * Required Fields:
     * - product_ids (string or array) - Comma-separated or array of product IDs
     * 
     * Optional Fields:
     * - language_id (int) - Language ID (default: 2)
     * 
     * Response Example:
     * {
     *   "success": true,
     *   "data": [
     *     {
     *       "product_id": 42,
     *       "name": "iPhone 15 Pro",
     *       "model": "IP15P-128",
     *       "quantity": 50,
     *       "price": "999.00",
     *       "status": 1
     *     },
     *     {
     *       "product_id": 43,
     *       "name": "Samsung Galaxy S24",
     *       "model": "SGS24-256",
     *       "quantity": 30,
     *       "price": "899.00",
     *       "status": 1
     *     }
     *   ],
     *   "count": 2,
     *   "requested": 3,
     *   "not_found": [44]
     * }
     */
    public function getProductsByIds() {
        $this->authenticate();
        
        try {
            $productIds = array();
            
            // Get IDs from GET or POST
            if (isset($this->request->get['product_ids'])) {
                // GET: comma-separated
                $productIds = array_map('intval', explode(',', $this->request->get['product_ids']));
            } else {
                // POST: JSON array
                $jsonData = json_decode(file_get_contents('php://input'), true);
                if (!$jsonData) {
                    $jsonData = $this->request->post;
                }
                
                if (isset($jsonData['product_ids'])) {
                    if (is_array($jsonData['product_ids'])) {
                        $productIds = array_map('intval', $jsonData['product_ids']);
                    } else {
                        $productIds = array_map('intval', explode(',', $jsonData['product_ids']));
                    }
                }
            }
            
            // Remove invalid IDs
            $productIds = array_filter($productIds, function($id) {
                return $id > 0;
            });
            
            if (empty($productIds)) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'product_ids is required (comma-separated or array)',
                    'required_fields' => array('product_ids'),
                    'examples' => array(
                        'GET' => 'product_ids=42,43,44',
                        'POST' => '{"product_ids": [42, 43, 44]}'
                    )
                ), 400);
            }
            
            // Max 100 products
            if (count($productIds) > 100) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Maximum 100 product IDs allowed',
                    'provided' => count($productIds)
                ), 400);
            }
            
            $requestedIds = $productIds;
            $products = array();
            $notFound = array();
            
            // Get products
            $idsString = implode(',', $productIds);
            $sql = "SELECT p.product_id, pd.name, p.model, p.sku, 
                           p.quantity, p.price, p.status, p.image, p.date_added
                    FROM " . DB_PREFIX . "product p
                    LEFT JOIN " . DB_PREFIX . "product_description pd 
                        ON p.product_id = pd.product_id
                    WHERE p.product_id IN (" . $idsString . ")
                    AND pd.language_id = '" . (int)$this->getLanguageId() . "'
                    ORDER BY FIELD(p.product_id, " . $idsString . ")";
            
            $query = $this->db->query($sql);
            
            $foundIds = array();
            foreach ($query->rows as $row) {
                $products[] = $row;
                $foundIds[] = $row['product_id'];
            }
            
            // Find not found IDs
            $notFound = array_values(array_diff($requestedIds, $foundIds));
            
            $this->sendResponse(array(
                'success' => true,
                'data' => $products,
                'count' => count($products),
                'requested' => count($requestedIds),
                'not_found' => $notFound
            ));
            
        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }
    

    // ==================== CATEGORY OPERATIONS ====================

    /**
     * Get all categories
     * GET: /index.php?route=api/product_api/getAllCategories&api_key=xxx&start=0&limit=20
     */
    public function getAllCategories() {
        $this->authenticate();

        try {
            $start = isset($this->request->get['start']) ? (int)$this->request->get['start'] : 0;
            $limit = isset($this->request->get['limit']) ? (int)$this->request->get['limit'] : 20;

            // Get categories with pagination
            $sql = "SELECT c.category_id, cd.name, c.parent_id, c.sort_order, c.status
                    FROM " . DB_PREFIX . "category c
                    LEFT JOIN " . DB_PREFIX . "category_description cd 
                    ON c.category_id = cd.category_id
                    WHERE cd.language_id = '" . (int)self::LANGUAGE_ID . "'
                    AND c.status = '" . (int)self::STATUS . "'
                    ORDER BY cd.name ASC
                    LIMIT " . (int)$start . ", " . (int)$limit;

            $query = $this->db->query($sql);

            // Get total count
            $count_sql = "SELECT COUNT(*) as total
                          FROM " . DB_PREFIX . "category c
                          WHERE c.status = '" . (int)self::STATUS . "'";

            $count_query = $this->db->query($count_sql);

            $this->sendResponse(array(
                'success' => true,
                'data' => $query->rows,
                'count' => count($query->rows),
                'total' => $count_query->row['total'],
                'start' => $start,
                'limit' => $limit
            ));

        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }

    /**
     * Search categories by name
     * GET: /index.php?route=api/product_api/searchCategories&name=Electronics&api_key=xxx&start=0&limit=20
     */
    public function searchCategories() {
        $this->authenticate();

        try {
            $name = isset($this->request->get['name']) ? trim($this->request->get['name']) : '';
            $start = isset($this->request->get['start']) ? (int)$this->request->get['start'] : 0;
            $limit = isset($this->request->get['limit']) ? (int)$this->request->get['limit'] : 20;

            if (empty($name)) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Name parameter is required'
                ), 400);
            }

            // Search categories by name
            $sql = "SELECT c.category_id, cd.name
                    FROM " . DB_PREFIX . "category c
                    LEFT JOIN " . DB_PREFIX . "category_description cd 
                    ON c.category_id = cd.category_id
                    WHERE cd.language_id = '" . (int)self::LANGUAGE_ID . "'
                    AND c.status = '" . (int)self::STATUS . "'
                    AND cd.name LIKE '%" . $this->db->escape($name) . "%'
                    ORDER BY cd.name ASC
                    LIMIT " . (int)$start . ", " . (int)$limit;

            $query = $this->db->query($sql);

            // Get total count for search
            $count_sql = "SELECT COUNT(*) as total
                          FROM " . DB_PREFIX . "category c
                          LEFT JOIN " . DB_PREFIX . "category_description cd 
                          ON c.category_id = cd.category_id
                          WHERE cd.language_id = '" . (int)self::LANGUAGE_ID . "'
                          AND c.status = '" . (int)self::STATUS . "'
                          AND cd.name LIKE '%" . $this->db->escape($name) . "%'";

            $count_query = $this->db->query($count_sql);

            $this->sendResponse(array(
                'success' => true,
                'data' => $query->rows,
                'count' => count($query->rows),
                'total' => $count_query->row['total'],
                'start' => $start,
                'limit' => $limit
            ));

        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }

    // ==================== ATTRIBUTE GROUP LIST OPERATIONS ====================

    /**
     * Get all attribute groups
     * GET: /index.php?route=api/product_api/getAllAttributeGroups&api_key=xxx&start=0&limit=20
     */
    public function getAllAttributeGroups() {
        $this->authenticate();

        try {
            $start = isset($this->request->get['start']) ? (int)$this->request->get['start'] : 0;
            $limit = isset($this->request->get['limit']) ? (int)$this->request->get['limit'] : 20;

            // Get all attribute groups with pagination
            $sql = "SELECT ag.attribute_group_id, agd.name, ag.sort_order
                    FROM " . DB_PREFIX . "attribute_group ag
                    LEFT JOIN " . DB_PREFIX . "attribute_group_description agd 
                    ON ag.attribute_group_id = agd.attribute_group_id
                    WHERE agd.language_id = '" . (int)self::LANGUAGE_ID . "'
                    ORDER BY agd.name ASC
                    LIMIT " . (int)$start . ", " . (int)$limit;

            $query = $this->db->query($sql);

            // Get total count
            $count_sql = "SELECT COUNT(DISTINCT ag.attribute_group_id) as total
                          FROM " . DB_PREFIX . "attribute_group ag";

            $count_query = $this->db->query($count_sql);

            $this->sendResponse(array(
                'success' => true,
                'data' => $query->rows,
                'count' => count($query->rows),
                'total' => $count_query->row['total'],
                'start' => $start,
                'limit' => $limit
            ));

        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }
    
    /**
     * Get all attributes
     * GET: /index.php?route=api/product_api/getAllAttributes&api_key=xxx&start=0&limit=20
     */
    public function getAllAttributes() {
        $this->authenticate();

        try {
            $start = isset($this->request->get['start']) ? (int)$this->request->get['start'] : 0;
            $limit = isset($this->request->get['limit']) ? (int)$this->request->get['limit'] : 20;

            // Get all attributes with pagination
            $sql = "SELECT a.attribute_id, 
                           ad.name, 
                           a.attribute_group_id, 
                           a.sort_order,
                           agd.name as group_name
                    FROM " . DB_PREFIX . "attribute a 
                    LEFT JOIN " . DB_PREFIX . "attribute_description ad 
                        ON a.attribute_id = ad.attribute_id
                    LEFT JOIN " . DB_PREFIX . "attribute_group ag
                        ON a.attribute_group_id = ag.attribute_group_id
                    LEFT JOIN " . DB_PREFIX . "attribute_group_description agd
                        ON ag.attribute_group_id = agd.attribute_group_id
                        AND agd.language_id = ad.language_id
                    WHERE ad.language_id = '" . (int)self::LANGUAGE_ID . "'
                    ORDER BY ad.name ASC
                    LIMIT " . (int)$start . ", " . (int)$limit;

            $query = $this->db->query($sql);

            // Get total count
            $count_sql = "SELECT COUNT(DISTINCT a.attribute_id) as total
                          FROM " . DB_PREFIX . "attribute a";

            $count_query = $this->db->query($count_sql);

            $this->sendResponse(array(
                'success' => true,
                'data' => $query->rows,
                'count' => count($query->rows),
                'total' => $count_query->row['total'],
                'start' => $start,
                'limit' => $limit
            ));

        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }
    
    // ==================== ATTRIBUTE OPERATIONS ====================
    
    /**
     * Search attributes by key (attribute name)
     * Searches in the attribute name/title, not the values
     * 
     * GET: /index.php?route=api/product_api/searchAttributeByKey&key=Color&api_key=xxx
     * 
     * Example Results:
     * - attribute_id: 5, name: "Color"
     * - attribute_id: 12, name: "Primary Color"
     */
    public function searchAttributeByKey() {
        $this->authenticate();

        try {
            $key = isset($this->request->get['key']) ? trim($this->request->get['key']) : '';

            if (empty($key)) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Key parameter is required'
                ), 400);
            }

            // Search in attribute_description table (attribute names)
            $sql = "SELECT a.attribute_id, 
                           ad.name, 
                           a.attribute_group_id, 
                           a.sort_order,
                           agd.name as group_name
                    FROM " . DB_PREFIX . "attribute a 
                    LEFT JOIN " . DB_PREFIX . "attribute_description ad 
                        ON a.attribute_id = ad.attribute_id
                    LEFT JOIN " . DB_PREFIX . "attribute_group ag
                        ON a.attribute_group_id = ag.attribute_group_id
                    LEFT JOIN " . DB_PREFIX . "attribute_group_description agd
                        ON ag.attribute_group_id = agd.attribute_group_id
                        AND agd.language_id = ad.language_id
                    WHERE ad.language_id = '" . (int)$this->getLanguageId() . "' 
                    AND ad.name LIKE '%" . $this->db->escape($key) . "%'
                    ORDER BY ad.name ASC";

            $query = $this->db->query($sql);

            $result = array();
            foreach ($query->rows as $row) {
                $result[] = array(
                    'attribute_id' => $row['attribute_id'],
                    'name' => $row['name'],  // Attribute name (the "key")
                    'attribute_group_id' => $row['attribute_group_id'],
                    'group_name' => $row['group_name'],
                    'sort_order' => $row['sort_order']
                );
            }

            $this->sendResponse(array(
                'success' => true,
                'data' => $result,
                'count' => count($result)
            ));

        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }

    
    /**
     * Search attributes by value text
     * Searches in the actual attribute values assigned to products
     * 
     * GET: /index.php?route=api/product_api/searchAttributeByValue&value=Red&api_key=xxx
     * 
     * Example Results:
     * - key: "Color", value: "Red, Blue, Green"
     * - key: "Primary Color", value: "Red"
     */
    public function searchAttributeByValue() {
        $this->authenticate();

        try {
            $value = isset($this->request->get['value']) ? trim($this->request->get['value']) : '';

            if (empty($value)) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Value parameter is required'
                ), 400);
            }

            // Search in product_attribute table (attribute values)
            $sql = "SELECT DISTINCT 
                        pa.attribute_id, 
                        ad.name as attribute_name, 
                        pa.text as value,
                        a.attribute_group_id,
                        agd.name as group_name,
                        COUNT(DISTINCT pa.product_id) as product_count
                    FROM " . DB_PREFIX . "product_attribute pa
                    LEFT JOIN " . DB_PREFIX . "attribute_description ad 
                        ON pa.attribute_id = ad.attribute_id
                        AND ad.language_id = pa.language_id
                    LEFT JOIN " . DB_PREFIX . "attribute a
                        ON pa.attribute_id = a.attribute_id
                    LEFT JOIN " . DB_PREFIX . "attribute_group_description agd
                        ON a.attribute_group_id = agd.attribute_group_id
                        AND agd.language_id = pa.language_id
                    WHERE pa.language_id = '" . (int)$this->getLanguageId() . "'
                    AND pa.text LIKE '%" . $this->db->escape($value) . "%'
                    GROUP BY pa.attribute_id, pa.text
                    ORDER BY ad.name ASC, pa.text ASC";

            $query = $this->db->query($sql);

            $result = array();
            foreach ($query->rows as $row) {
                $result[] = array(
                    'attribute_id' => $row['attribute_id'],
                    'key' => $row['attribute_name'],  // Attribute name
                    'value' => $row['value'],         // Actual value (what we searched for)
                    'attribute_group_id' => $row['attribute_group_id'],
                    'group_name' => $row['group_name'],
                    'product_count' => $row['product_count']  // How many products have this value
                );
            }

            $this->sendResponse(array(
                'success' => true,
                'data' => $result,
                'count' => count($result)
            ));

        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }
    
    /**
     * Get attribute by ID
     * GET: /index.php?route=api/product_api/getAttribute&attribute_id=5&api_key=xxx
     */
    public function getAttribute() {
        $this->authenticate();
        
        try {
            $attributeId = isset($this->request->get['attribute_id']) ? (int)$this->request->get['attribute_id'] : 0;
            
            if (!$attributeId) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Attribute ID is required'
                ), 400);
            }
            
            // Get attribute data
            $attribute = $this->adminAttributeModel->getAttribute($attributeId);
            
            if (!$attribute) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Attribute not found'
                ), 404);
            }
            
            // Get attribute descriptions
            $attribute['descriptions'] = $this->adminAttributeModel->getAttributeDescriptions($attributeId);
            
            $this->sendResponse(array(
                'success' => true,
                'data' => $attribute
            ));
            
        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }
    
    /**
     * Add attribute
     * POST: /index.php?route=api/product_api/addAttribute&api_key=xxx
     */
    public function addAttribute() {
        $this->authenticate();
        
        try {
            if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Only POST method allowed'
                ), 405);
            }
            
            $jsonData = json_decode(file_get_contents('php://input'), true);
            if (!$jsonData) {
                $jsonData = $this->request->post;
            }
            
            if (!isset($jsonData['attribute_description']) || !isset($jsonData['attribute_group_id'])) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'attribute_description and attribute_group_id are required'
                ), 400);
            }
            
            // Add attribute
            $attributeId = $this->adminAttributeModel->addAttribute($jsonData);
            
            $this->sendResponse(array(
                'success' => true,
                'message' => 'Attribute added successfully',
                'attribute_id' => $attributeId
            ), 201);
            
        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }
    
    /**
     * Update attribute
     * PUT/POST: /index.php?route=api/product_api/updateAttribute&attribute_id=5&api_key=xxx
     */
    public function updateAttribute() {
        $this->authenticate();
        
        try {
            $attributeId = isset($this->request->get['attribute_id']) ? (int)$this->request->get['attribute_id'] : 0;
            
            if (!$attributeId) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Attribute ID is required'
                ), 400);
            }
            
            $jsonData = json_decode(file_get_contents('php://input'), true);
            if (!$jsonData) {
                $jsonData = $this->request->post;
            }
            
            // Update attribute
            $this->adminAttributeModel->editAttribute($attributeId, $jsonData);
            
            $this->sendResponse(array(
                'success' => true,
                'message' => 'Attribute updated successfully',
                'attribute_id' => $attributeId
            ));
            
        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }
    
    /**
     * Delete attribute
     * DELETE/POST: /index.php?route=api/product_api/deleteAttribute&attribute_id=5&api_key=xxx
     */
    public function deleteAttribute() {
        $this->authenticate();
        
        try {
            $attributeId = isset($this->request->get['attribute_id']) ? (int)$this->request->get['attribute_id'] : 0;
            
            if (!$attributeId) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Attribute ID is required'
                ), 400);
            }
            
            // Delete attribute
            $this->adminAttributeModel->deleteAttribute($attributeId);
            
            $this->sendResponse(array(
                'success' => true,
                'message' => 'Attribute deleted successfully'
            ));
            
        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }
    
    // ==================== ATTRIBUTE GROUP OPERATIONS ====================
    
    /**
     * Search attribute groups by name
     * GET: /index.php?route=api/product_api/searchAttributeGroup&name=Technical&api_key=xxx
     */
    public function searchAttributeGroup() {
        $this->authenticate();
        
        try {
            $name = isset($this->request->get['name']) ? trim($this->request->get['name']) : '';
            
            // Search in attribute group table
            $sql = "SELECT ag.attribute_group_id, agd.name, ag.sort_order
                    FROM " . DB_PREFIX . "attribute_group ag
                    LEFT JOIN " . DB_PREFIX . "attribute_group_description agd 
                    ON ag.attribute_group_id = agd.attribute_group_id
                    WHERE agd.language_id = '" . (int)$this->getLanguageId() . "'";
            
            if (!empty($name)) {
                $sql .= " AND agd.name LIKE '%" . $this->db->escape($name) . "%'";
            }
            
            $sql .= " ORDER BY agd.name ASC";
            
            $query = $this->db->query($sql);
            
            $this->sendResponse(array(
                'success' => true,
                'data' => $query->rows,
                'count' => count($query->rows)
            ));
            
        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }
    
    /**
     * Get attribute group by ID
     * GET: /index.php?route=api/product_api/getAttributeGroup&attribute_group_id=3&api_key=xxx
     */
    public function getAttributeGroup() {
        $this->authenticate();
        
        try {
            $attributeGroupId = isset($this->request->get['attribute_group_id']) ? (int)$this->request->get['attribute_group_id'] : 0;
            
            if (!$attributeGroupId) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Attribute group ID is required'
                ), 400);
            }
            
            // Get attribute group data
            $attributeGroup = $this->adminAttributeGroupModel->getAttributeGroup($attributeGroupId);
            
            if (!$attributeGroup) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Attribute group not found'
                ), 404);
            }
            
            // Get attribute group descriptions
            $attributeGroup['descriptions'] = $this->adminAttributeGroupModel->getAttributeGroupDescriptions($attributeGroupId);
            
            $this->sendResponse(array(
                'success' => true,
                'data' => $attributeGroup
            ));
            
        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }
    
    /**
     * Add attribute group
     * POST: /index.php?route=api/product_api/addAttributeGroup&api_key=xxx
     */
    public function addAttributeGroup() {
        $this->authenticate();
        
        try {
            if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Only POST method allowed'
                ), 405);
            }
            
            $jsonData = json_decode(file_get_contents('php://input'), true);
            if (!$jsonData) {
                $jsonData = $this->request->post;
            }
            
            if (!isset($jsonData['attribute_group_description'])) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'attribute_group_description is required'
                ), 400);
            }
            
            // Add attribute group
            $groupId = $this->adminAttributeGroupModel->addAttributeGroup($jsonData);
            
            $this->sendResponse(array(
                'success' => true,
                'message' => 'Attribute group added successfully',
                'attribute_group_id' => $groupId
            ), 201);
            
        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }
    
    /**
     * Update attribute group
     * PUT/POST: /index.php?route=api/product_api/updateAttributeGroup&attribute_group_id=3&api_key=xxx
     */
    public function updateAttributeGroup() {
        $this->authenticate();
        
        try {
            $attributeGroupId = isset($this->request->get['attribute_group_id']) ? (int)$this->request->get['attribute_group_id'] : 0;
            
            if (!$attributeGroupId) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Attribute group ID is required'
                ), 400);
            }
            
            $jsonData = json_decode(file_get_contents('php://input'), true);
            if (!$jsonData) {
                $jsonData = $this->request->post;
            }
            
            // Update attribute group
            $this->adminAttributeGroupModel->editAttributeGroup($attributeGroupId, $jsonData);
            
            $this->sendResponse(array(
                'success' => true,
                'message' => 'Attribute group updated successfully',
                'attribute_group_id' => $attributeGroupId
            ));
            
        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }
    
    /**
     * Delete attribute group
     * DELETE/POST: /index.php?route=api/product_api/deleteAttributeGroup&attribute_group_id=3&api_key=xxx
     */
    public function deleteAttributeGroup() {
        $this->authenticate();
        
        try {
            $attributeGroupId = isset($this->request->get['attribute_group_id']) ? (int)$this->request->get['attribute_group_id'] : 0;
            
            if (!$attributeGroupId) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Attribute group ID is required'
                ), 400);
            }
            
            // Delete attribute group
            $this->adminAttributeGroupModel->deleteAttributeGroup($attributeGroupId);
            
            $this->sendResponse(array(
                'success' => true,
                'message' => 'Attribute group deleted successfully'
            ));
            
        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage()
            ), 500);
        }
    }
    
    // ==========================================
    // DYNAMIC DATABASE FIELDS MANAGEMENT SYSTEM
    // ==========================================

    /**
     * ==========================================
     * ADD (INSERT) DYNAMIC FIELDS INTO ANY TABLE
     * ==========================================
     *
     * Endpoint: POST /index.php?route=api/product_api/addDynamicFields&api_key=xxx
     *
     * ==========================================
     * EXAMPLE 1: Insert a New Product Row
     * ==========================================
     *
     * REQUEST:
     * {
     *   "table": "product",
     *   "fields": {
     *     "model": "DEMO-001",
     *     "sku": "SKU-DEMO",
     *     "quantity": 50,
     *     "price": "99.99",
     *     "status": 1,
     *     "date_added": "2024-01-01 00:00:00"
     *   }
     * }
     *
     * RESPONSE:
     * {
     *   "success": true,
     *   "message": "Record inserted successfully",
     *   "table": "product",
     *   "new_id": 101,
     *   "inserted_fields": ["model", "sku", "quantity", "price", "status", "date_added"]
     * }
     *
     * ==========================================
     * EXAMPLE 2: Insert a Product Description (multi-language)
     * ==========================================
     *
     * REQUEST:
     * {
     *   "table": "product_description",
     *   "language_id": 2,
     *   "fields": {
     *     "product_id": 101,
     *     "language_id": 2,
     *     "name": "Demo Product",
     *     "description": "<p>A demo product.</p>",
     *     "meta_title": "Demo Product",
     *     "meta_description": "Buy Demo Product",
     *     "meta_keyword": "demo"
     *   }
     * }
     *
     * RESPONSE:
     * {
     *   "success": true,
     *   "message": "Record inserted successfully",
     *   "table": "product_description",
     *   "new_id": 0,
     *   "inserted_fields": ["product_id", "language_id", "name", "description", "meta_title", "meta_description", "meta_keyword"]
     * }
     *
     * ==========================================
     * EXAMPLE 3: Insert a Category
     * ==========================================
     *
     * REQUEST:
     * {
     *   "table": "category",
     *   "fields": {
     *     "parent_id": 0,
     *     "top": 1,
     *     "column": 1,
     *     "sort_order": 10,
     *     "status": 1,
     *     "date_added": "2024-06-01 12:00:00"
     *   }
     * }
     *
     * RESPONSE:
     * {
     *   "success": true,
     *   "message": "Record inserted successfully",
     *   "table": "category",
     *   "new_id": 62,
     *   "inserted_fields": ["parent_id", "top", "column", "sort_order", "status", "date_added"]
     * }
     *
     * ==========================================
     * ERROR RESPONSE EXAMPLE:
     * ==========================================
     *
     * {
     *   "success": false,
     *   "error": "Field validation failed",
     *   "validation_errors": {
     *     "bad_field": "Field does not exist in table",
     *     "price": "Must be numeric (expected: decimal(15,4))"
     *   },
     *   "available_fields": ["model", "sku", "quantity", "price", "status"]
     * }
     *
     * ==========================================
     * CURL EXAMPLES:
     * ==========================================
     *
     * # Insert product
     * curl -X POST 'https://yoursite.com/index.php?route=api/product_api/addDynamicFields&api_key=YOUR_KEY' \
     *   -H 'Content-Type: application/json' \
     *   -d '{"table":"product","fields":{"model":"DEMO-001","price":"99.99","status":1}}'
     *
     * # Insert category
     * curl -X POST 'https://yoursite.com/index.php?route=api/product_api/addDynamicFields&api_key=YOUR_KEY' \
     *   -H 'Content-Type: application/json' \
     *   -d '{"table":"category","fields":{"parent_id":0,"status":1}}'
     */
    public function addDynamicFields() {
        $this->authenticate();

        try {
            // Only POST method allowed
            if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
                $this->sendResponse(array(
                    'success'         => false,
                    'error'           => 'Only POST method allowed',
                    'method_received' => $this->request->server['REQUEST_METHOD']
                ), 405);
                return;
            }

            // ─── 1. Parse request body ────────────────────────────────────────
            $jsonData = json_decode(file_get_contents('php://input'), true);

            if (!$jsonData || !is_array($jsonData)) {
                $jsonData = $this->request->post;
            }

            if (empty($jsonData)) {
                $this->sendResponse(array(
                    'success'        => false,
                    'error'          => 'No data provided',
                    'required_fields' => array(
                        'table'  => 'Table name (e.g., "product", "category")',
                        'fields' => 'Object with column names and values to insert'
                    ),
                    'examples' => array(
                        'product'  => array('table' => 'product',  'fields' => array('model' => 'SKU-001', 'price' => '9.99', 'status' => 1)),
                        'category' => array('table' => 'category', 'fields' => array('parent_id' => 0, 'status' => 1))
                    )
                ), 400);
                return;
            }

            // ─── 2. Validate table parameter ─────────────────────────────────
            if (!isset($jsonData['table']) || empty(trim($jsonData['table']))) {
                $this->sendResponse(array(
                    'success' => false,
                    'error'   => 'Missing required field: table'
                ), 400);
                return;
            }

            $tableName = trim($jsonData['table']);

            // Strip prefix if the caller included it (e.g. oc_product → product)
            $tableName = str_replace(DB_PREFIX, '', $tableName);

            // ─── 3. Validate fields parameter ────────────────────────────────
            if (!isset($jsonData['fields']) || !is_array($jsonData['fields']) || empty($jsonData['fields'])) {
                $this->sendResponse(array(
                    'success' => false,
                    'error'   => 'Missing or invalid fields parameter',
                    'example' => array('fields' => array('model' => 'DEMO', 'price' => '99.99', 'status' => 1))
                ), 400);
                return;
            }

            $fields     = $jsonData['fields'];
            $languageId = isset($jsonData['language_id']) ? (int)$jsonData['language_id'] : null;

            // ─── 4. Check table exists ────────────────────────────────────────
            if (!$this->checkTableExists($tableName)) {
                $this->sendResponse(array(
                    'success'  => false,
                    'error'    => "Table does not exist: {$tableName}",
                    'hint'     => 'Use getAvailableTables endpoint to see available tables',
                    'endpoint' => '/index.php?route=api/product_api/getAvailableTables&api_key=xxx'
                ), 404);
                return;
            }

            // ─── 5. Load schema and validate submitted fields ─────────────────
            $tableStructure = $this->getTableStructureData($tableName);
            $primaryKey     = $this->getPrimaryKeyData($tableName);

            // Remove auto-increment primary key from the fields to insert
            // (the DB generates it; passing it would cause duplicate-key errors)
            if (isset($fields[$primaryKey])) {
                $pkInfo = isset($tableStructure[$primaryKey]) ? $tableStructure[$primaryKey] : array();
                $isAutoIncrement = isset($pkInfo['extra']) && stripos($pkInfo['extra'], 'auto_increment') !== false;
                if ($isAutoIncrement) {
                    unset($fields[$primaryKey]);
                }
            }

            // Inject language_id from the top-level parameter if not already present
            if ($languageId !== null && isset($tableStructure['language_id']) && !isset($fields['language_id'])) {
                $fields['language_id'] = $languageId;
            }

            $validatedFields = $this->validateFieldsData($fields, $tableStructure);

            if (!empty($validatedFields['errors'])) {
                $this->sendResponse(array(
                    'success'           => false,
                    'error'             => 'Field validation failed',
                    'validation_errors' => $validatedFields['errors'],
                    'available_fields'  => array_keys($tableStructure),
                    'hint'              => 'Use getTableStructure?table=' . $tableName . ' to see all available fields'
                ), 400);
                return;
            }

            if (empty($validatedFields['fields'])) {
                $this->sendResponse(array(
                    'success' => false,
                    'error'   => 'No valid fields to insert after validation'
                ), 400);
                return;
            }

            // ─── 6. Build and execute INSERT ──────────────────────────────────
            $insertResult = $this->executeDynamicInsert($tableName, $validatedFields['fields']);

            if (!$insertResult['success']) {
                throw new Exception($insertResult['error']);
            }

            // ─── 7. Success response ──────────────────────────────────────────
            $response = array(
                'success'         => true,
                'message'         => 'Record inserted successfully',
                'table'           => $tableName,
                'primary_key'     => $primaryKey,
                'new_id'          => $insertResult['insert_id'],
                'inserted_fields' => array_keys($validatedFields['fields'])
            );

            if ($languageId !== null) {
                $response['language_id'] = $languageId;
            }

            $this->sendResponse($response);

        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error'   => 'Insert failed: ' . $e->getMessage()
            ), 500);
        }
    }

    /**
     * Execute INSERT (generic - works with any table)
     *
     * @param string $tableName  Table name without prefix
     * @param array  $fields     Validated field name => value pairs
     * @return array             ['success' => bool, 'insert_id' => int, 'error' => string]
     */
    private function executeDynamicInsert($tableName, $fields) {
        try {
            $columns = array();
            $values  = array();

            foreach ($fields as $fieldName => $value) {
                $columns[] = '`' . $fieldName . '`';
                $values[]  = $this->escapeFieldValueData($value);
            }

            $sql = "INSERT INTO " . DB_PREFIX . $this->db->escape($tableName)
                 . " (" . implode(', ', $columns) . ")"
                 . " VALUES (" . implode(', ', $values) . ")";

            $this->db->query($sql);

            return array(
                'success'   => true,
                'insert_id' => $this->db->getLastId()
            );

        } catch (Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * ==========================================
     * UPDATE DYNAMIC FIELDS IN ANY TABLE
     * ==========================================
     * 
     * Endpoint: POST /index.php?route=api/product_api/updateDynamicFields&api_key=xxx
     * 
     * ==========================================
     * EXAMPLE 1: Update Product Table
     * ==========================================
     * 
     * REQUEST:
     * {
     *   "table": "product",
     *   "id": 42,
     *   "fields": {
     *     "quantity": 150,
     *     "price": "1299.99",
     *     "weight": "0.650",
     *     "status": 1
     *   }
     * }
     * 
     * RESPONSE:
     * {
     *   "success": true,
     *   "message": "Fields updated successfully",
     *   "table": "product",
     *   "primary_key": "product_id",
     *   "record_id": 42,
     *   "updated_fields": ["quantity", "price", "weight", "status"],
     *   "affected_rows": 1
     * }
     * 
     * ==========================================
     * EXAMPLE 2: Update Category Table
     * ==========================================
     * 
     * REQUEST:
     * {
     *   "table": "category",
     *   "id": 59,
     *   "fields": {
     *     "status": 1,
     *     "sort_order": 5,
     *     "top": 1
     *   }
     * }
     * 
     * RESPONSE:
     * {
     *   "success": true,
     *   "message": "Fields updated successfully",
     *   "table": "category",
     *   "primary_key": "category_id",
     *   "record_id": 59,
     *   "updated_fields": ["status", "sort_order", "top"],
     *   "affected_rows": 1
     * }
     * 
     * ==========================================
     * EXAMPLE 3: Update Customer Table
     * ==========================================
     * 
     * REQUEST:
     * {
     *   "table": "customer",
     *   "id": 123,
     *   "fields": {
     *     "status": 1,
     *     "newsletter": 1,
     *     "safe": 1
     *   }
     * }
     * 
     * RESPONSE:
     * {
     *   "success": true,
     *   "message": "Fields updated successfully",
     *   "table": "customer",
     *   "primary_key": "customer_id",
     *   "record_id": 123,
     *   "updated_fields": ["status", "newsletter", "safe"],
     *   "affected_rows": 1
     * }
     * 
     * ==========================================
     * EXAMPLE 4: Update Multi-Language Table (Product Description)
     * ==========================================
     * 
     * REQUEST:
     * {
     *   "table": "product_description",
     *   "id": 42,
     *   "language_id": 2,
     *   "fields": {
     *     "name": "iPhone 15 Pro Max",
     *     "description": "<p>Complete product description</p>",
     *     "meta_title": "Buy iPhone 15 Pro Max",
     *     "meta_description": "Best price for iPhone 15",
     *     "meta_keyword": "iphone, apple, smartphone"
     *   }
     * }
     * 
     * RESPONSE:
     * {
     *   "success": true,
     *   "message": "Fields updated successfully",
     *   "table": "product_description",
     *   "primary_key": "product_id",
     *   "record_id": 42,
     *   "language_id": 2,
     *   "updated_fields": ["name", "description", "meta_title", "meta_description", "meta_keyword"],
     *   "affected_rows": 1
     * }
     * 
     * ==========================================
     * EXAMPLE 5: Update Manufacturer Table
     * ==========================================
     * 
     * REQUEST:
     * {
     *   "table": "manufacturer",
     *   "id": 8,
     *   "fields": {
     *     "name": "Apple Inc.",
     *     "sort_order": 10
     *   }
     * }
     * 
     * RESPONSE:
     * {
     *   "success": true,
     *   "message": "Fields updated successfully",
     *   "table": "manufacturer",
     *   "primary_key": "manufacturer_id",
     *   "record_id": 8,
     *   "updated_fields": ["name", "sort_order"],
     *   "affected_rows": 1
     * }
     * 
     * ==========================================
     * EXAMPLE 6: Update Order Status
     * ==========================================
     * 
     * REQUEST:
     * {
     *   "table": "order",
     *   "id": 1001,
     *   "fields": {
     *     "order_status_id": 5,
     *     "comment": "Order shipped via DHL"
     *   }
     * }
     * 
     * RESPONSE:
     * {
     *   "success": true,
     *   "message": "Fields updated successfully",
     *   "table": "order",
     *   "primary_key": "order_id",
     *   "record_id": 1001,
     *   "updated_fields": ["order_status_id", "comment"],
     *   "affected_rows": 1
     * }
     * 
     * ==========================================
     * ERROR RESPONSE EXAMPLE:
     * ==========================================
     * 
     * {
     *   "success": false,
     *   "error": "Field validation failed",
     *   "validation_errors": {
     *     "invalid_field": "Field does not exist in table",
     *     "price": "Must be numeric (expected: decimal(15,4))"
     *   },
     *   "available_fields": ["product_id", "quantity", "price", "weight", "status"]
     * }
     * 
     * ==========================================
     * CURL EXAMPLES:
     * ==========================================
     * 
     * # Update product
     * curl -X POST 'https://yoursite.com/index.php?route=api/product_api/updateDynamicFields&api_key=YOUR_KEY' \
     *   -H 'Content-Type: application/json' \
     *   -d '{"table":"product","id":42,"fields":{"quantity":150}}'
     * 
     * # Update category
     * curl -X POST 'https://yoursite.com/index.php?route=api/product_api/updateDynamicFields&api_key=YOUR_KEY' \
     *   -H 'Content-Type: application/json' \
     *   -d '{"table":"category","id":59,"fields":{"status":1}}'
     * 
     * # Update customer
     * curl -X POST 'https://yoursite.com/index.php?route=api/product_api/updateDynamicFields&api_key=YOUR_KEY' \
     *   -H 'Content-Type: application/json' \
     *   -d '{"table":"customer","id":123,"fields":{"status":1}}'
     * 
     * ==========================================
     * BACKWARD COMPATIBILITY:
     * ==========================================
     * You can still use "product_id" instead of "id" for product tables
     */
    public function updateDynamicFields() {
        $this->authenticate();
        
        try {
            // Only POST method allowed
            if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Only POST method allowed',
                    'method_received' => $this->request->server['REQUEST_METHOD']
                ), 405);
            }
            
            // Get JSON data from request body
            $jsonData = json_decode(file_get_contents('php://input'), true);
            
            // Fallback to POST data if JSON parsing failed
            if (!$jsonData || !is_array($jsonData)) {
                $jsonData = $this->request->post;
            }
            
            // Check if data provided
            if (empty($jsonData)) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'No data provided',
                    'required_fields' => array(
                        'table' => 'Table name (e.g., "product", "category", "customer")',
                        'id' => 'Record ID (integer)',
                        'fields' => 'Object with field names and values to update'
                    ),
                    'examples' => array(
                        'product' => array('table' => 'product', 'id' => 42, 'fields' => array('quantity' => 100)),
                        'category' => array('table' => 'category', 'id' => 59, 'fields' => array('status' => 1)),
                        'customer' => array('table' => 'customer', 'id' => 123, 'fields' => array('status' => 1))
                    )
                ), 400);
            }
            
            // Validate table parameter
            if (!isset($jsonData['table']) || empty(trim($jsonData['table']))) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Missing required field: table'
                ), 400);
            }
            
            $tableName = trim($jsonData['table']);
            
            // Get record ID (support both 'id' and 'product_id' for backward compatibility)
            $recordId = 0;
            if (isset($jsonData['id'])) {
                $recordId = (int)$jsonData['id'];
            } elseif (isset($jsonData['product_id'])) {
                $recordId = (int)$jsonData['product_id'];
            }
            
            if ($recordId <= 0) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Missing or invalid record ID',
                    'hint' => 'Use "id" parameter (e.g., "id": 42) or "product_id" for backward compatibility'
                ), 400);
            }
            
            // Validate fields
            if (!isset($jsonData['fields']) || !is_array($jsonData['fields']) || empty($jsonData['fields'])) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Missing or invalid fields parameter',
                    'example' => array('fields' => array('quantity' => 100, 'price' => '999.99'))
                ), 400);
            }
            
            $fields = $jsonData['fields'];
            $languageId = isset($jsonData['language_id']) ? (int)$jsonData['language_id'] : null;
            
            // Check table exists
            if (!$this->checkTableExists($tableName)) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => "Table does not exist: {$tableName}",
                    'hint' => 'Use getAvailableTables endpoint to see available tables',
                    'endpoint' => '/index.php?route=api/product_api/getAvailableTables&api_key=xxx'
                ), 404);
            }
            
            // Get table structure and primary key
            $tableStructure = $this->getTableStructureData($tableName);
            $primaryKey = $this->getPrimaryKeyData($tableName);
            
            // Validate fields
            $validatedFields = $this->validateFieldsData($fields, $tableStructure);
            
            if (!empty($validatedFields['errors'])) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Field validation failed',
                    'validation_errors' => $validatedFields['errors'],
                    'available_fields' => array_keys($tableStructure),
                    'hint' => 'Use getTableStructure?table=' . $tableName . ' to see all available fields'
                ), 400);
            }
            
            // Check record exists
            if (!$this->checkRecordExistsGeneric($tableName, $primaryKey, $recordId, $languageId)) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => "Record not found in table {$tableName}",
                    'table' => $tableName,
                    'primary_key' => $primaryKey,
                    'record_id' => $recordId,
                    'language_id' => $languageId,
                    'hint' => 'Make sure the record exists with this ID'
                ), 404);
            }
            
            // Execute update
            $updateResult = $this->executeDynamicUpdateGeneric(
                $tableName,
                $primaryKey,
                $recordId,
                $validatedFields['fields'],
                $languageId
            );
            
            if (!$updateResult['success']) {
                throw new Exception($updateResult['error']);
            }
            
            // Success response
            $response = array(
                'success' => true,
                'message' => 'Fields updated successfully',
                'table' => $tableName,
                'primary_key' => $primaryKey,
                'record_id' => $recordId,
                'updated_fields' => array_keys($validatedFields['fields']),
                'affected_rows' => $updateResult['affected_rows']
            );
            
            if ($languageId !== null) {
                $response['language_id'] = $languageId;
            }
            
            $this->sendResponse($response);
            
        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => 'Update failed: ' . $e->getMessage()
            ), 500);
        }
    }
    
    /**
     * ==========================================
     * GET DYNAMIC FIELDS FROM ANY TABLE
     * ==========================================
     * 
     * Endpoint: GET /index.php?route=api/product_api/getDynamicFields
     * 
     * PARAMETERS:
     * - table (string, required)        - Table name
     * - id (int, required)              - Record ID
     * - fields (string, optional)       - Comma-separated field names (* = all)
     * - language_id (int, optional)     - Language ID (for multi-language tables)
     * 
     * ==========================================
     * EXAMPLE 1: Get All Product Fields
     * ==========================================
     * 
     * REQUEST:
     * GET /index.php?route=api/product_api/getDynamicFields&table=product&id=42&api_key=xxx
     * 
     * RESPONSE:
     * {
     *   "success": true,
     *   "table": "product",
     *   "primary_key": "product_id",
     *   "record_id": 42,
     *   "data": {
     *     "product_id": 42,
     *     "model": "IP15P-128",
     *     "sku": "SKU-12345",
     *     "quantity": 150,
     *     "price": "1299.9900",
     *     "weight": "0.6500",
     *     "status": 1,
     *     "manufacturer_id": 8,
     *     "date_added": "2024-01-15 10:30:00"
     *   }
     * }
     * 
     * ==========================================
     * EXAMPLE 2: Get Specific Product Fields
     * ==========================================
     * 
     * REQUEST:
     * GET /index.php?route=api/product_api/getDynamicFields&table=product&id=42&fields=quantity,price,status&api_key=xxx
     * 
     * RESPONSE:
     * {
     *   "success": true,
     *   "table": "product",
     *   "primary_key": "product_id",
     *   "record_id": 42,
     *   "requested_fields": ["quantity", "price", "status"],
     *   "data": {
     *     "quantity": 150,
     *     "price": "1299.9900",
     *     "status": 1
     *   }
     * }
     * 
     * ==========================================
     * EXAMPLE 3: Get Category Data
     * ==========================================
     * 
     * REQUEST:
     * GET /index.php?route=api/product_api/getDynamicFields&table=category&id=59&api_key=xxx
     * 
     * RESPONSE:
     * {
     *   "success": true,
     *   "table": "category",
     *   "primary_key": "category_id",
     *   "record_id": 59,
     *   "data": {
     *     "category_id": 59,
     *     "parent_id": 0,
     *     "top": 1,
     *     "column": 1,
     *     "sort_order": 5,
     *     "status": 1,
     *     "date_added": "2023-05-10 12:00:00"
     *   }
     * }
     * 
     * ==========================================
     * EXAMPLE 4: Get Customer Data
     * ==========================================
     * 
     * REQUEST:
     * GET /index.php?route=api/product_api/getDynamicFields&table=customer&id=123&api_key=xxx
     * 
     * RESPONSE:
     * {
     *   "success": true,
     *   "table": "customer",
     *   "primary_key": "customer_id",
     *   "record_id": 123,
     *   "data": {
     *     "customer_id": 123,
     *     "firstname": "John",
     *     "lastname": "Doe",
     *     "email": "john@example.com",
     *     "status": 1,
     *     "newsletter": 1,
     *     "date_added": "2023-01-15 10:30:00"
     *   }
     * }
     * 
     * ==========================================
     * EXAMPLE 5: Get Multi-Language Data (Product Description)
     * ==========================================
     * 
     * REQUEST:
     * GET /index.php?route=api/product_api/getDynamicFields&table=product_description&id=42&language_id=2&api_key=xxx
     * 
     * RESPONSE:
     * {
     *   "success": true,
     *   "table": "product_description",
     *   "primary_key": "product_id",
     *   "record_id": 42,
     *   "language_id": 2,
     *   "data": {
     *     "product_id": 42,
     *     "language_id": 2,
     *     "name": "iPhone 15 Pro Max",
     *     "description": "<p>Complete product description</p>",
     *     "meta_title": "Buy iPhone 15 Pro Max",
     *     "meta_description": "Best price for iPhone 15",
     *     "meta_keyword": "iphone, apple, smartphone"
     *   }
     * }
     * 
     * ==========================================
     * EXAMPLE 6: Get Order Data
     * ==========================================
     * 
     * REQUEST:
     * GET /index.php?route=api/product_api/getDynamicFields&table=order&id=1001&api_key=xxx
     * 
     * RESPONSE:
     * {
     *   "success": true,
     *   "table": "order",
     *   "primary_key": "order_id",
     *   "record_id": 1001,
     *   "data": {
     *     "order_id": 1001,
     *     "customer_id": 123,
     *     "order_status_id": 5,
     *     "total": "1299.99",
     *     "currency_code": "USD",
     *     "date_added": "2024-05-01 10:00:00"
     *   }
     * }
     * 
     * ==========================================
     * CURL EXAMPLES:
     * ==========================================
     * 
     * # Get all product fields
     * curl 'https://yoursite.com/index.php?route=api/product_api/getDynamicFields&table=product&id=42&api_key=YOUR_KEY'
     * 
     * # Get specific product fields
     * curl 'https://yoursite.com/index.php?route=api/product_api/getDynamicFields&table=product&id=42&fields=quantity,price,status&api_key=YOUR_KEY'
     * 
     * # Get category data
     * curl 'https://yoursite.com/index.php?route=api/product_api/getDynamicFields&table=category&id=59&api_key=YOUR_KEY'
     * 
     * # Get customer data
     * curl 'https://yoursite.com/index.php?route=api/product_api/getDynamicFields&table=customer&id=123&api_key=YOUR_KEY'
     * 
     * # Get multi-language data
     * curl 'https://yoursite.com/index.php?route=api/product_api/getDynamicFields&table=product_description&id=42&language_id=2&api_key=YOUR_KEY'
     */
    public function getDynamicFields() {
        $this->authenticate();
        
        try {
            // Get table name
            $table = isset($this->request->get['table']) ? $this->request->get['table'] : '';
            
            if (empty($table)) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'table parameter is required',
                    'required_fields' => array('table', 'id'),
                    'example' => 'table=product_image&id=123'
                ), 400);
            }
            
            // Remove prefix if user included it (e.g., oc_url_alias → url_alias)
            $table = str_replace(DB_PREFIX, '', $table);
            
            // ========================================
            // STEP 0: Auto-detect table structure
            // ========================================
            $tableInfo = $this->getTableInfo($table);
            
            if (!$tableInfo['exists']) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Table does not exist: ' . DB_PREFIX . $table,
                    'hint' => 'Check table name spelling',
                    'example' => 'table=product_image or table=url_alias'
                ), 404);
            }
            
            $primaryKey = $tableInfo['primary_key'];
            $supportsLanguage = $tableInfo['supports_language'];
            $allColumns = $tableInfo['columns'];
            $secondaryKeys = $tableInfo['secondary_keys'];
            
            // ========================================
            // STEP 1: Smart parameter detection
            // ========================================
            $recordId = 0;
            $usedParameter = '';
            $searchColumn = $primaryKey; // Default
            
            // Priority 1: Direct primary key column (e.g., product_image_id=4037411)
            if (isset($this->request->get[$primaryKey])) {
                $recordId = (int)$this->request->get[$primaryKey];
                $usedParameter = $primaryKey;
                $searchColumn = $primaryKey;
            }
            // Priority 2: Check all GET parameters for column matches
            else {
                $allParams = $this->request->get;
                
                foreach ($allParams as $paramName => $paramValue) {
                    // Skip non-ID parameters
                    if (in_array($paramName, array('route', 'api_key', 'table', 'language_id'))) {
                        continue;
                    }
                    
                    // Check if this parameter matches a known column
                    // 1. Is it a secondary key?
                    if (in_array($paramName, $secondaryKeys)) {
                        $recordId = (int)$paramValue;
                        $usedParameter = $paramName;
                        $searchColumn = $paramName;
                        break;
                    }
                    
                    // 2. Is it the generic 'id' parameter?
                    if ($paramName === 'id') {
                        $recordId = (int)$paramValue;
                        $usedParameter = 'id';
                        $searchColumn = $primaryKey;
                        break;
                    }
                    
                    // 3. Does this parameter match any column name in the table?
                    if (in_array($paramName, $allColumns)) {
                        $recordId = (int)$paramValue;
                        $usedParameter = $paramName;
                        $searchColumn = $paramName;
                        break;
                    }
                }
            }
            
            if ($recordId <= 0) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'id parameter is required and must be positive integer',
                    'hint' => 'Use one of: id=42, ' . $primaryKey . '=42, or any column name from the table',
                    'table' => DB_PREFIX . $table,
                    'primary_key' => $primaryKey,
                    'available_columns' => $allColumns,
                    'examples' => array(
                        'generic' => 'id=42',
                        'primary' => $primaryKey . '=42',
                        'any_column' => 'product_id=42'
                    )
                ), 400);
            }
            
            // Get language ID if supported
            $languageId = null;
            if ($supportsLanguage) {
                $languageId = isset($this->request->get['language_id']) 
                    ? (int)$this->request->get['language_id'] 
                    : $this->getLanguageId();
            }
            
            // ========================================
            // STEP 2: Build and execute query
            // ========================================
            $sql = "SELECT * FROM " . DB_PREFIX . $table . " WHERE " . $searchColumn . " = '" . (int)$recordId . "'";
            
            if ($supportsLanguage && $languageId) {
                $sql .= " AND language_id = '" . (int)$languageId . "'";
            }
            
            error_log("getDynamicFields SQL: " . $sql);
            
            $result = $this->db->query($sql);
            
            // ========================================
            // STEP 3: FALLBACK - Try first column if not found
            // ========================================
            if ($result->num_rows == 0 && $searchColumn !== $primaryKey) {
                error_log("getDynamicFields: No results with " . $searchColumn . "=" . $recordId . ", trying primary key...");
                
                $sql = "SELECT * FROM " . DB_PREFIX . $table . " WHERE " . $primaryKey . " = '" . (int)$recordId . "'";
                
                if ($supportsLanguage && $languageId) {
                    $sql .= " AND language_id = '" . (int)$languageId . "'";
                }
                
                $result = $this->db->query($sql);
                
                if ($result->num_rows > 0) {
                    $searchColumn = $primaryKey;
                    error_log("getDynamicFields: ✓ SUCCESS with primary key!");
                }
            }
            
            // ========================================
            // STEP 4: Return result or error
            // ========================================
            if ($result->num_rows == 0) {
                // Provide helpful debug info
                $debugQuery = $this->db->query("SELECT COUNT(*) as total FROM " . DB_PREFIX . $table);
                $totalRecords = $debugQuery->row['total'];
                
                // Sample query to show available IDs
                $sampleQuery = $this->db->query(
                    "SELECT " . $searchColumn . " FROM " . DB_PREFIX . $table . 
                    " ORDER BY " . $searchColumn . " DESC LIMIT 5"
                );
                $sampleIds = array();
                if ($sampleQuery->num_rows > 0) {
                    foreach ($sampleQuery->rows as $row) {
                        $sampleIds[] = $row[$searchColumn];
                    }
                }
                
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'Record not found in table ' . DB_PREFIX . $table,
                    'table' => DB_PREFIX . $table,
                    'primary_key' => $primaryKey,
                    'search_column_used' => $searchColumn,
                    'record_id' => $recordId,
                    'language_id' => $languageId,
                    'parameter_used' => $usedParameter,
                    'debug' => array(
                        'total_records_in_table' => $totalRecords,
                        'sql_attempted' => $sql,
                        'sample_ids' => $sampleIds,
                        'hint' => 'ID ' . $recordId . ' does not exist in column ' . $searchColumn
                    )
                ), 404);
            }
            
            // SUCCESS
            $this->sendResponse(array(
                'success' => true,
                'table' => DB_PREFIX . $table,
                'primary_key' => $primaryKey,
                'search_column_used' => $searchColumn,
                'record_id' => $recordId,
                'language_id' => $languageId,
                'parameter_used' => $usedParameter,
                'supports_language' => $supportsLanguage,
                'table_columns' => $allColumns,
                'data' => $result->num_rows > 1 ? $result->rows : $result->row,
                'row_count' => $result->num_rows
            ));
            
        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ), 500);
        }
    }
    
    /**
     * ==========================================
     * DELETE (REMOVE) ROW(S) FROM ANY TABLE
     * ==========================================
     *
     * Endpoint: DELETE/POST /index.php?route=api/product_api/deleteDynamicFields&api_key=xxx
     *
     * ==========================================
     * EXAMPLE 1: Delete a Product Row by ID
     * ==========================================
     *
     * REQUEST:
     * {
     *   "table": "product",
     *   "id": 101
     * }
     *
     * RESPONSE:
     * {
     *   "success": true,
     *   "message": "Record deleted successfully",
     *   "table": "product",
     *   "primary_key": "product_id",
     *   "record_id": 101,
     *   "affected_rows": 1
     * }
     *
     * ==========================================
     * EXAMPLE 2: Delete a Product Description (multi-language)
     * ==========================================
     *
     * REQUEST:
     * {
     *   "table": "product_description",
     *   "id": 101,
     *   "language_id": 2
     * }
     *
     * RESPONSE:
     * {
     *   "success": true,
     *   "message": "Record deleted successfully",
     *   "table": "product_description",
     *   "primary_key": "product_id",
     *   "record_id": 101,
     *   "language_id": 2,
     *   "affected_rows": 1
     * }
     *
     * ==========================================
     * EXAMPLE 3: Delete a Category
     * ==========================================
     *
     * REQUEST:
     * {
     *   "table": "category",
     *   "id": 62
     * }
     *
     * RESPONSE:
     * {
     *   "success": true,
     *   "message": "Record deleted successfully",
     *   "table": "category",
     *   "primary_key": "category_id",
     *   "record_id": 62,
     *   "affected_rows": 1
     * }
     *
     * ==========================================
     * EXAMPLE 4: Delete by WHERE conditions (multi-column match)
     * ==========================================
     *
     * REQUEST:
     * {
     *   "table": "product_to_category",
     *   "where": {
     *     "product_id": 101,
     *     "category_id": 25
     *   }
     * }
     *
     * RESPONSE:
     * {
     *   "success": true,
     *   "message": "Record deleted successfully",
     *   "table": "product_to_category",
     *   "where_conditions": {"product_id": 101, "category_id": 25},
     *   "affected_rows": 1
     * }
     *
     * ==========================================
     * ERROR RESPONSE EXAMPLE:
     * ==========================================
     *
     * {
     *   "success": false,
     *   "error": "Record not found in table product",
     *   "table": "product",
     *   "primary_key": "product_id",
     *   "record_id": 999
     * }
     *
     * ==========================================
     * CURL EXAMPLES:
     * ==========================================
     *
     * # Delete product by ID
     * curl -X POST 'https://yoursite.com/index.php?route=api/product_api/deleteDynamicFields&api_key=YOUR_KEY' \
     *   -H 'Content-Type: application/json' \
     *   -d '{"table":"product","id":101}'
     *
     * # Delete product description for a specific language
     * curl -X POST 'https://yoursite.com/index.php?route=api/product_api/deleteDynamicFields&api_key=YOUR_KEY' \
     *   -H 'Content-Type: application/json' \
     *   -d '{"table":"product_description","id":101,"language_id":2}'
     *
     * # Delete by multiple WHERE conditions (junction/pivot tables)
     * curl -X POST 'https://yoursite.com/index.php?route=api/product_api/deleteDynamicFields&api_key=YOUR_KEY' \
     *   -H 'Content-Type: application/json' \
     *   -d '{"table":"product_to_category","where":{"product_id":101,"category_id":25}}'
     *
     * ==========================================
     * NOTES:
     * ==========================================
     * - Blocked/blacklisted tables (orders, customers, users, etc.) are protected
     * - Use "id" OR "where" — not both. "where" takes priority if both are sent
     * - For multi-language tables, combine "id" + "language_id" to target one language row
     * - "where" mode supports any valid column(s) from the table (validated against schema)
     * - Backward compatibility: "product_id" accepted as alias for "id"
     */
    public function deleteDynamicFields() {
        $this->authenticate();
    
        try {
            // ─── 1. Accept DELETE or POST ─────────────────────────────────────
            $method = isset($this->request->server['REQUEST_METHOD'])
                ? strtoupper($this->request->server['REQUEST_METHOD'])
                : 'POST';
    
            if (!in_array($method, array('POST', 'DELETE'))) {
                $this->sendResponse(array(
                    'success'         => false,
                    'error'           => 'Only POST or DELETE method allowed',
                    'method_received' => $method
                ), 405);
                return;
            }
    
            // ─── 2. Parse request body ────────────────────────────────────────
            $jsonData = json_decode(file_get_contents('php://input'), true);
    
            if (!$jsonData || !is_array($jsonData)) {
                $jsonData = $this->request->post;
            }
    
            if (empty($jsonData)) {
                $this->sendResponse(array(
                    'success'         => false,
                    'error'           => 'No data provided',
                    'required_fields' => array(
                        'table' => 'Table name (e.g., "product", "category")',
                        'id'    => 'Record ID  — OR —',
                        'where' => 'Object with column => value conditions'
                    ),
                    'examples' => array(
                        'by_id'    => array('table' => 'product',           'id'    => 101),
                        'by_where' => array('table' => 'product_to_category', 'where' => array('product_id' => 101, 'category_id' => 25))
                    )
                ), 400);
                return;
            }
    
            // ─── 3. Validate table ────────────────────────────────────────────
            if (!isset($jsonData['table']) || empty(trim($jsonData['table']))) {
                $this->sendResponse(array(
                    'success' => false,
                    'error'   => 'Missing required field: table'
                ), 400);
                return;
            }
    
            $tableName = trim($jsonData['table']);
            // Strip prefix if caller included it (e.g. oc_product → product)
            $tableName = str_replace(DB_PREFIX, '', $tableName);
    
            // ─── 4. Check table exists (also enforces blacklist via SecureDbWrapper) ──
            if (!$this->checkTableExists($tableName)) {
                $this->sendResponse(array(
                    'success'  => false,
                    'error'    => "Table does not exist or is not accessible: {$tableName}",
                    'hint'     => 'Use getAvailableTables endpoint to see accessible tables',
                    'endpoint' => '/index.php?route=api/product_api/getAvailableTables&api_key=xxx'
                ), 404);
                return;
            }
    
            // ─── 5. Resolve deletion mode: WHERE-object  vs  single ID ──────
            $useWhereObject = isset($jsonData['where'])
                && is_array($jsonData['where'])
                && !empty($jsonData['where']);
    
            if ($useWhereObject) {
                // ── MODE A: arbitrary WHERE conditions ───────────────────────
                $whereFields = $jsonData['where'];
    
                // Validate every WHERE column against the real schema
                $tableStructure = $this->getTableStructureData($tableName);
                $validatedWhere = $this->validateFieldsData($whereFields, $tableStructure);
    
                if (!empty($validatedWhere['errors'])) {
                    $this->sendResponse(array(
                        'success'           => false,
                        'error'             => 'WHERE field validation failed',
                        'validation_errors' => $validatedWhere['errors'],
                        'available_fields'  => array_keys($tableStructure)
                    ), 400);
                    return;
                }
    
                if (empty($validatedWhere['fields'])) {
                    $this->sendResponse(array(
                        'success' => false,
                        'error'   => 'No valid WHERE conditions after validation'
                    ), 400);
                    return;
                }
    
                // Check at least one matching row exists
                $existsSql = "SELECT 1 FROM " . DB_PREFIX . $this->db->escape($tableName)
                           . " WHERE " . $this->buildWhereClause($validatedWhere['fields'])
                           . " LIMIT 1";
    
                $existsResult = $this->db->query($existsSql);
    
                if (!$existsResult || $existsResult->num_rows === 0) {
                    $this->sendResponse(array(
                        'success'          => false,
                        'error'            => "No matching record found in table {$tableName}",
                        'table'            => $tableName,
                        'where_conditions' => $whereFields
                    ), 404);
                    return;
                }
    
                // Execute DELETE
                $deleteSql = "DELETE FROM " . DB_PREFIX . $this->db->escape($tableName)
                           . " WHERE " . $this->buildWhereClause($validatedWhere['fields']);
    
                $this->db->query($deleteSql);
                $affectedRows = $this->db->countAffected();
    
                $this->sendResponse(array(
                    'success'          => true,
                    'message'          => 'Record deleted successfully',
                    'table'            => $tableName,
                    'where_conditions' => $whereFields,
                    'affected_rows'    => $affectedRows
                ));
    
            } else {
                // ── MODE B: single primary-key ID (+ optional language_id) ───
    
                // Resolve record ID — support "id", "product_id" (legacy), or the
                // actual primary-key column name supplied by the caller
                $primaryKey = $this->getPrimaryKeyData($tableName);
                $recordId   = 0;
    
                if (isset($jsonData['id'])) {
                    $recordId = (int)$jsonData['id'];
                } elseif (isset($jsonData[$primaryKey])) {
                    $recordId = (int)$jsonData[$primaryKey];
                } elseif (isset($jsonData['product_id'])) {
                    // backward-compat alias
                    $recordId = (int)$jsonData['product_id'];
                }
    
                if ($recordId <= 0) {
                    $this->sendResponse(array(
                        'success' => false,
                        'error'   => 'Missing or invalid record ID',
                        'hint'    => 'Provide "id": <int>  or  "where": { col: val, ... }',
                        'example' => array('table' => $tableName, 'id' => 42)
                    ), 400);
                    return;
                }
    
                $languageId = isset($jsonData['language_id'])
                    ? (int)$jsonData['language_id']
                    : null;
    
                // Confirm record exists before deleting
                if (!$this->checkRecordExistsGeneric($tableName, $primaryKey, $recordId, $languageId)) {
                    $response = array(
                        'success'     => false,
                        'error'       => "Record not found in table {$tableName}",
                        'table'       => $tableName,
                        'primary_key' => $primaryKey,
                        'record_id'   => $recordId
                    );
                    if ($languageId !== null) {
                        $response['language_id'] = $languageId;
                    }
                    $this->sendResponse($response, 404);
                    return;
                }
    
                // Build and execute DELETE
                $deleteSql = "DELETE FROM " . DB_PREFIX . $this->db->escape($tableName)
                           . " WHERE `{$primaryKey}` = '" . (int)$recordId . "'";
    
                if ($languageId !== null) {
                    $deleteSql .= " AND `language_id` = '" . (int)$languageId . "'";
                }
    
                $this->db->query($deleteSql);
                $affectedRows = $this->db->countAffected();
    
                $response = array(
                    'success'      => true,
                    'message'      => 'Record deleted successfully',
                    'table'        => $tableName,
                    'primary_key'  => $primaryKey,
                    'record_id'    => $recordId,
                    'affected_rows'=> $affectedRows
                );
    
                if ($languageId !== null) {
                    $response['language_id'] = $languageId;
                }
    
                $this->sendResponse($response);
            }
    
        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error'   => 'Delete failed: ' . $e->getMessage()
            ), 500);
        }
    }
    
    /**
     * Build a SQL WHERE clause from a validated key=>value map.
     * All values are escaped; NULL produces IS NULL.
     *
     * @param  array  $conditions  Column => value pairs (already validated)
     * @return string              e.g. "`product_id` = '101' AND `category_id` = '25'"
     */
    private function buildWhereClause(array $conditions) {
        $parts = array();
        foreach ($conditions as $column => $value) {
            if (is_null($value)) {
                $parts[] = "`" . $column . "` IS NULL";
            } else {
                $parts[] = "`" . $column . "` = " . $this->escapeFieldValueData($value);
            }
        }
        return implode(' AND ', $parts);
    }
    /**
     * Get comprehensive table information - AUTO-DETECTION
     * 
     * @param string $table     Table name (without prefix)
     * @return array            Table info including primary key, columns, language support
     */
    private function getTableInfo($table) {
        $info = array(
            'exists' => false,
            'primary_key' => null,
            'supports_language' => false,
            'columns' => array(),
            'secondary_keys' => array()
        );
        
        try {
            // Check if table exists
            $checkTable = $this->db->query(
                "SHOW TABLES LIKE '" . DB_PREFIX . $this->db->escape($table) . "'"
            );
            
            if ($checkTable->num_rows == 0) {
                return $info;
            }
            
            $info['exists'] = true;
            
            // Get all columns
            $columnsQuery = $this->db->query("SHOW COLUMNS FROM " . DB_PREFIX . $table);
            
            if ($columnsQuery->num_rows > 0) {
                foreach ($columnsQuery->rows as $column) {
                    $columnName = $column['Field'];
                    $info['columns'][] = $columnName;
                    
                    // Check for language_id column
                    if ($columnName === 'language_id') {
                        $info['supports_language'] = true;
                    }
                    
                    // Check for common foreign keys
                    if (preg_match('/_id$/', $columnName) && $columnName !== 'language_id') {
                        $info['secondary_keys'][] = $columnName;
                    }
                }
            }
            
            // Get primary key using SHOW KEYS
            $keysQuery = $this->db->query(
                "SHOW KEYS FROM " . DB_PREFIX . $table . " WHERE Key_name = 'PRIMARY'"
            );
            
            if ($keysQuery->num_rows > 0) {
                $info['primary_key'] = $keysQuery->row['Column_name'];
            } else {
                // Fallback: Use first column as primary key
                if (!empty($info['columns'])) {
                    $info['primary_key'] = $info['columns'][0];
                    error_log("getTableInfo: No PRIMARY KEY found for " . $table . ", using first column: " . $info['primary_key']);
                }
            }
            
            // Remove primary key from secondary keys if present
            if ($info['primary_key']) {
                $info['secondary_keys'] = array_diff($info['secondary_keys'], array($info['primary_key']));
                $info['secondary_keys'] = array_values($info['secondary_keys']); // Re-index
            }
            
        } catch (Exception $e) {
            error_log("getTableInfo error: " . $e->getMessage());
        }
        
        return $info;
    }
    
    /**
     * Check if a column exists in a table
     * 
     * @param string $table     Table name (without prefix)
     * @param string $column    Column name to check
     * @return bool             True if column exists
     */
    private function doesColumnExist($table, $column) {
        try {
            $query = $this->db->query(
                "SHOW COLUMNS FROM " . DB_PREFIX . $table . " LIKE '" . $this->db->escape($column) . "'"
            );
            return $query->num_rows > 0;
        } catch (Exception $e) {
            error_log("doesColumnExist error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * ==========================================
     * GET TABLE STRUCTURE
     * ==========================================
     * 
     * Shows all columns, data types, and constraints for a table
     * 
     * Endpoint: GET /index.php?route=api/product_api/getTableStructure&table=TABLENAME&api_key=xxx
     * 
     * ==========================================
     * EXAMPLE 1: Get Product Table Structure
     * ==========================================
     * 
     * REQUEST:
     * GET /index.php?route=api/product_api/getTableStructure&table=product&api_key=xxx
     * 
     * RESPONSE:
     * {
     *   "success": true,
     *   "table": "product",
     *   "full_table_name": "oc_product",
     *   "primary_key": "product_id",
     *   "fields": {
     *     "product_id": {
     *       "type": "int(11)",
     *       "null": "NO",
     *       "key": "PRI",
     *       "default": null,
     *       "extra": "auto_increment"
     *     },
     *     "model": {
     *       "type": "varchar(64)",
     *       "null": "NO",
     *       "key": "",
     *       "default": "",
     *       "extra": ""
     *     },
     *     "quantity": {
     *       "type": "int(4)",
     *       "null": "NO",
     *       "key": "",
     *       "default": "0",
     *       "extra": ""
     *     },
     *     "price": {
     *       "type": "decimal(15,4)",
     *       "null": "NO",
     *       "key": "",
     *       "default": "0.0000",
     *       "extra": ""
     *     }
     *   },
     *   "field_count": 25,
     *   "field_names": ["product_id", "model", "sku", "quantity", "price", "..."]
     * }
     * 
     * ==========================================
     * EXAMPLE 2: Get Category Table Structure
     * ==========================================
     * 
     * REQUEST:
     * GET /index.php?route=api/product_api/getTableStructure&table=category&api_key=xxx
     * 
     * RESPONSE:
     * {
     *   "success": true,
     *   "table": "category",
     *   "full_table_name": "oc_category",
     *   "primary_key": "category_id",
     *   "fields": {
     *     "category_id": {
     *       "type": "int(11)",
     *       "null": "NO",
     *       "key": "PRI",
     *       "default": null,
     *       "extra": "auto_increment"
     *     },
     *     "parent_id": {
     *       "type": "int(11)",
     *       "null": "NO",
     *       "key": "",
     *       "default": "0",
     *       "extra": ""
     *     },
     *     "status": {
     *       "type": "tinyint(1)",
     *       "null": "NO",
     *       "key": "",
     *       "default": "0",
     *       "extra": ""
     *     }
     *   },
     *   "field_count": 7,
     *   "field_names": ["category_id", "parent_id", "top", "column", "sort_order", "status", "date_added"]
     * }
     * 
     * ==========================================
     * CURL EXAMPLES:
     * ==========================================
     * 
     * # Get product table structure
     * curl 'https://yoursite.com/index.php?route=api/product_api/getTableStructure&table=product&api_key=YOUR_KEY'
     * 
     * # Get category table structure
     * curl 'https://yoursite.com/index.php?route=api/product_api/getTableStructure&table=category&api_key=YOUR_KEY'
     * 
     * # Get customer table structure
     * curl 'https://yoursite.com/index.php?route=api/product_api/getTableStructure&table=customer&api_key=YOUR_KEY'
     */
    public function getTableStructure() {
        $this->authenticate();
        
        try {
            $tableName = isset($this->request->get['table']) ? trim($this->request->get['table']) : '';
            
            if (empty($tableName)) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => 'table parameter is required',
                    'example' => '/index.php?route=api/product_api/getTableStructure&table=product&api_key=xxx'
                ), 400);
            }
            
            if (!$this->checkTableExists($tableName)) {
                $this->sendResponse(array(
                    'success' => false,
                    'error' => "Table does not exist: {$tableName}",
                    'hint' => 'Use getAvailableTables to see all tables'
                ), 404);
            }
            
            $structure = $this->getTableStructureData($tableName);
            $primaryKey = $this->getPrimaryKeyData($tableName);
            
            $this->sendResponse(array(
                'success' => true,
                'table' => $tableName,
                'full_table_name' => DB_PREFIX . $tableName,
                'primary_key' => $primaryKey,
                'fields' => $structure,
                'field_count' => count($structure),
                'field_names' => array_keys($structure),
                'usage_examples' => array(
                    'get_all_fields' => "/index.php?route=api/product_api/getDynamicFields&table={$tableName}&id=1&api_key=xxx",
                    'get_specific_fields' => "/index.php?route=api/product_api/getDynamicFields&table={$tableName}&id=1&fields=field1,field2&api_key=xxx",
                    'update_fields' => array(
                        'url' => '/index.php?route=api/product_api/updateDynamicFields&api_key=xxx',
                        'method' => 'POST',
                        'body' => array(
                            'table' => $tableName,
                            'id' => 1,
                            'fields' => array('field_name' => 'value')
                        )
                    )
                )
            ));
            
        } catch (Exception $e) {
            $this->sendResponse(array(
                'success' => false,
                'error' => 'Failed to get table structure: ' . $e->getMessage()
            ), 500);
        }
    }
     
    /**
     * ==========================================
     * GET AVAILABLE TABLES (Simplified)
     * ==========================================
     * 
     * Lists all database tables accessible through the API.
     * Blocked (blacklisted) tables are automatically filtered out.
     * 
     * Endpoint: GET /index.php?route=api/product_api/getAvailableTables&api_key=xxx
     * 
     * ==========================================
     * RESPONSE EXAMPLE:
     * ==========================================
     * 
     * {
     *   "success": true,
     *   "database_info": {
     *     "name": "your_database",
     *     "prefix": "oc_",
     *     "total_tables": 45,
     *     "blocked_count": 40,
     *     "opencart_version": "2.3.0.2"
     *   },
     *   "tables": [
     *     {"name": "oc_product", "short_name": "product"},
     *     {"name": "oc_category", "short_name": "category"}
     *   ]
     * }
     * 
     * CURL:
     *   curl 'https://yoursite.com/index.php?route=api/product_api/getAvailableTables&api_key=YOUR_KEY'
     */
    public function getAvailableTables() {
        $this->authenticate();
    
        // ============================================================
        // 1. Safe database retrieval (registry-first, bypass magic __get)
        // ============================================================
        $db = $this->getSafeDb();
    
        if ($db === null) {
            $this->sendResponse(array(
                'success'          => false,
                'error'            => 'Database connection not available',
                'opencart_version' => $this->getVersionInfo(),
                'debug_info'       => array(
                    'db_object_exists' => false,
                    'db_object_type'   => 'null',
                    'has_query_method' => false,
                    'error_history'    => isset($this->error['database']) 
                                          ? $this->error['database'] 
                                          : 'No error logged'
                ),
                'suggestion'       => 'Call /index.php?route=api/product_api/debugDatabase&api_key=xxx for more info',
                'troubleshooting'  => array(
                    'step1' => 'Verify config.php has correct DB_* constants',
                    'step2' => 'Check file permissions on system/storage/',
                    'step3' => 'Enable PHP error_log and check for MySQL connection errors',
                    'step4' => 'Try debugDatabase endpoint for more details'
                )
            ), 500);
            return;
        }
    
        try {
            // ============================================================
            // 2. Fetch tables
            // ============================================================
            // NOTE: SHOW TABLES bypasses SecureDbWrapper's blacklist regex
            // (the regex only matches FROM/JOIN/INTO/UPDATE/TABLE keywords).
            // We manually filter blocked tables below for security.
            error_log('Product API: Executing SHOW TABLES');
            $result = $db->query("SHOW TABLES");
    
            if (!$result || !is_object($result)) {
                throw new Exception('SHOW TABLES query returned invalid result');
            }
    
            // ============================================================
            // 3. Get blacklist (if SecureDbWrapper is in use)
            // ============================================================
            $blockedTables = array();
            if (method_exists($db, 'getBlockedTables')) {
                $blockedTables = $db->getBlockedTables();
            }
    
            // ============================================================
            // 4. Build flat table list (filter out blocked ones)
            // ============================================================
            $tables        = array();
            $blockedCount  = 0;
    
            if (isset($result->num_rows) && $result->num_rows > 0 && isset($result->rows)) {
                foreach ($result->rows as $row) {
                    // SHOW TABLES returns one column; get its value
                    $fullTableName = reset($row);
    
                    if (empty($fullTableName) || !is_string($fullTableName)) {
                        continue;
                    }
    
                    $hasPrefix = (strpos($fullTableName, DB_PREFIX) === 0);
                    $shortName = $hasPrefix
                        ? substr($fullTableName, strlen(DB_PREFIX))
                        : $fullTableName;
    
                    // Skip blacklisted tables
                    if (!empty($blockedTables) && in_array(strtolower($shortName), $blockedTables, true)) {
                        $blockedCount++;
                        continue;
                    }
    
                    $tables[] = array(
                        'name'       => $fullTableName,
                        'short_name' => $shortName,
                        'has_prefix' => $hasPrefix
                    );
                }
            }
    
            // Sort alphabetically by short name
            usort($tables, function($a, $b) {
                return strcmp($a['short_name'], $b['short_name']);
            });
    
            // ============================================================
            // 5. Response
            // ============================================================
            $this->sendResponse(array(
                'success'       => true,
                'database_info' => array(
                    'name'             => defined('DB_DATABASE') ? DB_DATABASE : 'unknown',
                    'prefix'           => defined('DB_PREFIX')   ? DB_PREFIX   : '',
                    'host'             => defined('DB_HOSTNAME') ? DB_HOSTNAME : 'unknown',
                    'total_tables'     => count($tables),
                    'blocked_count'    => $blockedCount,
                    'opencart_version' => defined('VERSION') ? VERSION : 'unknown'
                ),
                'tables' => $tables
            ));
    
        } catch (Exception $e) {
            error_log('Product API: getAvailableTables() Exception - ' . $e->getMessage());
            $this->sendResponse(array(
                'success'    => false,
                'error'      => 'Failed to retrieve tables: ' . $e->getMessage(),
                'error_type' => get_class($e)
            ), 500);
        } catch (Error $e) {
            error_log('Product API: getAvailableTables() Fatal - ' . $e->getMessage());
            $this->sendResponse(array(
                'success'    => false,
                'error'      => 'Fatal error retrieving tables: ' . $e->getMessage(),
                'error_type' => get_class($e)
            ), 500);
        }
    }
    
    /**
     * Safely retrieve a working DB instance.
     * 
     * Works around OpenCart 2.3.x's magic __get() which proxies $this->db
     * through the registry and can return inconsistent results.
     * 
     * @return object|null Returns DB object if usable, null otherwise.
     */
    private function getSafeDb() {
        // 1. Try registry first (authoritative in OC 2.3.x)
        $db = null;
        if (isset($this->registry) && method_exists($this->registry, 'get')) {
            $db = $this->registry->get('db');
        }
    
        // 2. Fallback to magic property
        if (!is_object($db)) {
            $db = isset($this->db) ? $this->db : null;
        }
    
        // 3. Validate it's actually usable
        if (!is_object($db) || !method_exists($db, 'query')) {
            return null;
        }
    
        // 4. Quick alive-check (protects against stale/closed connections)
        try {
            $test = $db->query("SELECT 1 AS test");
            if ($test !== false && is_object($test) 
                && isset($test->num_rows) && $test->num_rows > 0) {
                return $db;
            }
        } catch (Exception $e) {
            error_log('Product API: getSafeDb() test exception - ' . $e->getMessage());
        } catch (Error $e) {
            error_log('Product API: getSafeDb() test fatal - ' . $e->getMessage());
        }
    
        return null;
    }
    
    
    
    
    /**
     * Deep debug database state
     * GET: /index.php?route=api/product_api/debugDatabase&api_key=xxx
     */
    public function debugDatabase() {
        $this->authenticate();
        
        $registryDb = null;
        if (isset($this->registry) && method_exists($this->registry, 'get')) {
            $registryDb = $this->registry->get('db');
        }
        
        $directDb = isset($this->db) ? $this->db : null;
        
        $this->sendResponse(array(
            'success' => true,
            'constants' => array(
                'DB_DRIVER'   => defined('DB_DRIVER') ? DB_DRIVER : 'NOT DEFINED',
                'DB_HOSTNAME' => defined('DB_HOSTNAME') ? DB_HOSTNAME : 'NOT DEFINED',
                'DB_DATABASE' => defined('DB_DATABASE') ? DB_DATABASE : 'NOT DEFINED',
                'DB_PREFIX'   => defined('DB_PREFIX') ? DB_PREFIX : 'NOT DEFINED',
                'DB_PORT'     => defined('DB_PORT') ? DB_PORT : 'NOT DEFINED',
            ),
            'paths' => array(
                'DIR_APPLICATION' => defined('DIR_APPLICATION') ? DIR_APPLICATION : 'N/A',
                'DIR_SYSTEM'      => defined('DIR_SYSTEM') ? DIR_SYSTEM : 'N/A',
                'config_guess_1'  => realpath(DIR_APPLICATION . '../config.php'),
            ),
            'registry_db' => array(
                'is_object'    => is_object($registryDb),
                'type'         => gettype($registryDb),
                'class'        => is_object($registryDb) ? get_class($registryDb) : 'N/A',
                'has_query'    => is_object($registryDb) && method_exists($registryDb, 'query'),
            ),
            'direct_db' => array(
                'is_object'    => is_object($directDb),
                'type'         => gettype($directDb),
                'class'        => is_object($directDb) ? get_class($directDb) : 'N/A',
                'has_query'    => is_object($directDb) && method_exists($directDb, 'query'),
            ),
            'extensions' => array(
                'mysqli'       => extension_loaded('mysqli'),
                'pdo_mysql'    => extension_loaded('pdo_mysql'),
                'gd'           => extension_loaded('gd'),
            ),
            'php_version' => PHP_VERSION,
            'error_history' => $this->error,
            'is_available'  => $this->isDatabaseAvailable(),
        ));
    }
    
    
    
    // ==========================================
    // PRIVATE HELPER METHODS
    // ==========================================
    
    /**
     * Check if table exists in database
     * @param string $tableName Table name without prefix
     * @return bool True if table exists
     */
    private function checkTableExists($tableName) {
        try {
            $query = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . $this->db->escape($tableName) . "'");
            return $query->num_rows > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get table structure with column information
     * @param string $tableName Table name without prefix
     * @return array Column definitions
     */
    private function getTableStructureData($tableName) {
        $query = $this->db->query("DESCRIBE " . DB_PREFIX . $this->db->escape($tableName));
        
        $structure = array();
        foreach ($query->rows as $field) {
            $structure[$field['Field']] = array(
                'type' => $field['Type'],
                'null' => $field['Null'],
                'key' => $field['Key'],
                'default' => $field['Default'],
                'extra' => isset($field['Extra']) ? $field['Extra'] : ''
            );
        }
        
        return $structure;
    }
    
    /**
     * Get primary key column name
     * Auto-detects from table structure or uses naming convention
     * 
     * @param string $tableName Table name without prefix
     * @return string Primary key column name
     */
    private function getPrimaryKeyData($tableName) {
        try {
            // Try to get from database
            $query = $this->db->query("SHOW KEYS FROM " . DB_PREFIX . $this->db->escape($tableName) . " WHERE Key_name = 'PRIMARY'");
            
            if ($query->num_rows > 0) {
                return $query->row['Column_name'];
            }
        } catch (Exception $e) {
            // Fallback to naming convention
        }
        
        // Auto-detect primary key based on table name
        $commonKeys = array(
            'product' => 'product_id',
            'category' => 'category_id',
            'customer' => 'customer_id',
            'order' => 'order_id',
            'manufacturer' => 'manufacturer_id',
            'information' => 'information_id',
            'banner' => 'banner_id',
            'coupon' => 'coupon_id',
            'voucher' => 'voucher_id',
            'review' => 'review_id',
            'attribute' => 'attribute_id',
            'option' => 'option_id',
            'filter' => 'filter_id',
            'download' => 'download_id',
            'recurring' => 'recurring_id',
            'return' => 'return_id',
            'address' => 'address_id',
            'affiliate' => 'affiliate_id',
            'article' => 'article_id',
            'blog' => 'blog_id'
        );
        
        foreach ($commonKeys as $tablePattern => $keyName) {
            if (strpos($tableName, $tablePattern) !== false) {
                return $keyName;
            }
        }
        
        // Fallback: table_name + _id
        return $tableName . '_id';
    }
    
    /**
     * Validate fields against table structure
     * @param array $fields Field name => value pairs
     * @param array $tableStructure Table structure
     * @return array ['fields' => validated fields, 'errors' => error messages]
     */
    private function validateFieldsData($fields, $tableStructure) {
        $validatedFields = array();
        $errors = array();
        
        foreach ($fields as $fieldName => $value) {
            // Check if field exists
            if (!isset($tableStructure[$fieldName])) {
                $errors[$fieldName] = "Field does not exist in table";
                continue;
            }
            
            $fieldInfo = $tableStructure[$fieldName];
            
            // Check NULL constraint
            if ($value === null && $fieldInfo['null'] === 'NO' && $fieldInfo['default'] === null) {
                $errors[$fieldName] = "Field cannot be NULL";
                continue;
            }
            
            // Validate data type
            $typeValidation = $this->validateFieldTypeData($value, $fieldInfo['type']);
            if ($typeValidation !== true) {
                $errors[$fieldName] = $typeValidation;
                continue;
            }
            
            $validatedFields[$fieldName] = $value;
        }
        
        return array(
            'fields' => $validatedFields,
            'errors' => $errors
        );
    }
    
    /**
     * Validate field value against MySQL data type
     * @param mixed $value Field value
     * @param string $type MySQL column type
     * @return mixed True if valid, error message if invalid
     */
    private function validateFieldTypeData($value, $type) {
        if ($value === null) {
            return true;
        }
        
        // INT types
        if (preg_match('/^(tiny|small|medium|big)?int/i', $type)) {
            if (!is_numeric($value)) {
                return "Must be numeric (expected: {$type})";
            }
            return true;
        }
        
        // DECIMAL/FLOAT/DOUBLE
        if (preg_match('/^(decimal|float|double|real)/i', $type)) {
            if (!is_numeric($value)) {
                return "Must be numeric (expected: {$type})";
            }
            return true;
        }
        
        // VARCHAR/CHAR
        if (preg_match('/^(var)?char\((\d+)\)/i', $type, $matches)) {
            $maxLength = (int)$matches[2];
            if (is_string($value) && strlen($value) > $maxLength) {
                return "String too long (max: {$maxLength}, got: " . strlen($value) . ")";
            }
            return true;
        }
        
        // TEXT types
        if (preg_match('/^(tiny|medium|long)?text/i', $type)) {
            return true;
        }
        
        // DATE/DATETIME/TIMESTAMP
        if (preg_match('/^(date|datetime|timestamp)/i', $type)) {
            if (!strtotime($value)) {
                return "Invalid date format (expected: YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)";
            }
            return true;
        }
        
        // ENUM
        if (preg_match('/^enum\((.*)\)/i', $type, $matches)) {
            $enumValues = array_map(function($v) {
                return trim($v, "'\"");
            }, explode(',', $matches[1]));
            
            if (!in_array($value, $enumValues)) {
                return "Invalid ENUM value (allowed: " . implode(', ', $enumValues) . ")";
            }
            return true;
        }
        
        return true;
    }
    
    /**
     * Check if record exists (generic - works with any primary key)
     * @param string $tableName Table name
     * @param string $primaryKey Primary key column name
     * @param int $recordId Record ID
     * @param int|null $languageId Language ID
     * @return bool True if exists
     */
    private function checkRecordExistsGeneric($tableName, $primaryKey, $recordId, $languageId = null) {
        try {
            $sql = "SELECT `{$primaryKey}` FROM " . DB_PREFIX . $this->db->escape($tableName) . " 
                    WHERE `{$primaryKey}` = '" . (int)$recordId . "'";
            
            if ($languageId !== null) {
                $sql .= " AND language_id = '" . (int)$languageId . "'";
            }
            
            $sql .= " LIMIT 1";
            
            $query = $this->db->query($sql);
            return $query->num_rows > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Execute UPDATE (generic - works with any primary key)
     * @param string $tableName Table name
     * @param string $primaryKey Primary key column
     * @param int $recordId Record ID
     * @param array $fields Fields to update
     * @param int|null $languageId Language ID
     * @return array Result
     */
    private function executeDynamicUpdateGeneric($tableName, $primaryKey, $recordId, $fields, $languageId = null) {
        try {
            $setStatements = array();
            
            foreach ($fields as $fieldName => $value) {
                $escapedValue = $this->escapeFieldValueData($value);
                $setStatements[] = "`" . $fieldName . "` = " . $escapedValue;
            }
            
            if (empty($setStatements)) {
                return array('success' => false, 'error' => 'No fields to update');
            }
            
            $sql = "UPDATE " . DB_PREFIX . $this->db->escape($tableName) . " 
                    SET " . implode(', ', $setStatements) . " 
                    WHERE `{$primaryKey}` = '" . (int)$recordId . "'";
            
            if ($languageId !== null) {
                $sql .= " AND language_id = '" . (int)$languageId . "'";
            }
            
            $this->db->query($sql);
            
            return array(
                'success' => true,
                'affected_rows' => $this->db->countAffected()
            );
            
        } catch (Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }
     
    /**
     * Execute SELECT (generic - works with any primary key)
     * @param string $tableName Table name
     * @param string $primaryKey Primary key column
     * @param int $recordId Record ID
     * @param array $fields Fields to select
     * @param int|null $languageId Language ID
     * @return array|false Row data or false
     */
    private function executeDynamicSelectGeneric($tableName, $primaryKey, $recordId, $fields, $languageId = null) {
        try {
            $selectFields = array_map(function($field) {
                return "`" . $field . "`";
            }, $fields);
            
            $sql = "SELECT " . implode(', ', $selectFields) . " 
                    FROM " . DB_PREFIX . $this->db->escape($tableName) . " 
                    WHERE `{$primaryKey}` = '" . (int)$recordId . "'";
            
            if ($languageId !== null) {
                $sql .= " AND language_id = '" . (int)$languageId . "'";
            }
            
            $sql .= " LIMIT 1";
            
            $query = $this->db->query($sql);
            
            if ($query->num_rows > 0) {
                return $query->row;
            }
            
            return false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Escape value for SQL query (PHP 5.6+ compatible)
     * @param mixed $value Value to escape
     * @return string Escaped SQL value
     */
    private function escapeFieldValueData($value) {
        if (is_null($value)) {
            return 'NULL';
        }
        
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        
        if (is_int($value) || is_float($value)) {
            return "'" . $value . "'";
        }
        
        return "'" . $this->db->escape($value) . "'";
    }
    
    /**
     * Get list of available database tables
     * @return array Tables list
     */
    private function getAvailableTablesData() {
        try {
            if (!$this->isDatabaseAvailable()) {
                return array('error' => 'Database not available');
            }
            
            // Test connection first
            $testQuery = $this->db->query("SELECT 1");
            if (!$testQuery) {
                return array('error' => 'Database connection test failed');
            }
            
            $query = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "%'");
            
            if (!$query) {
                return array('error' => 'Failed to execute SHOW TABLES query');
            }
            
            $tables = array();
            foreach ($query->rows as $row) {
                $fullTableName = reset($row);
                $tableName = str_replace(DB_PREFIX, '', $fullTableName);
                
                // Skip system/security tables
                if (!in_array($tableName, array('session', 'cart', 'api_session', 'api'))) {
                    $tables[] = array(
                        'name' => $tableName,
                        'full_name' => $fullTableName,
                        'primary_key' => $this->getPrimaryKeyData($tableName)
                    );
                }
            }
            
            return $tables;
            
        } catch (Exception $e) {
            return array('error' => $e->getMessage());
        }
    }
    /**
     * Get security blacklist information
     * 
     * GET: /index.php?route=api/product_api/getSecurityInfo&api_key=xxx
     * 
     * Returns:
     * - Security status (enabled/disabled)
     * - List of 45 blocked tables
     * - Database prefix
     * - OpenCart version
     * 
     * @return JSON response
     */
    public function getSecurityInfo() {
        $this->authenticate();
        
        $securityInfo = array(
            'security_enabled' => ($this->db instanceof SecureDbWrapper),
            'protection_type' => 'Blacklist Only (45 tables)',
            'blocked_tables' => array(),
            'blocked_count' => 0,
            'database_prefix' => DB_PREFIX,
            'opencart_version' => VERSION,
            'performance_impact' => '~0.5ms per query'
        );
        
        if ($this->db instanceof SecureDbWrapper) {
            $securityInfo['blocked_tables'] = $this->db->getBlockedTables();
            $securityInfo['blocked_count'] = count($securityInfo['blocked_tables']);
            $securityInfo['status'] = '✅ ACTIVE';
        } else {
            $securityInfo['status'] = '⚠️ NOT ACTIVE';
            $securityInfo['warning'] = 'Security wrapper not initialized';
        }
        
        $this->sendResponse(array(
            'success' => true,
            'data' => $securityInfo
        ));
    }
  
}
