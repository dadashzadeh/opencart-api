<?php
class ControllerApiBase extends Controller {
    protected $apiKey;
    protected $apiUser;
    protected $adminPath;
    protected $modificationType = null;
    protected $ocVersion;
    protected $modificationPath;
    protected $vqmodPath;
    
    public function __construct($registry) {
        parent::__construct($registry);
        
        // تشخیص نسخه OpenCart
        $this->detectOCVersion();
        
        // تشخیص مسیرها
        $this->detectPaths();
        
        // تشخیص نوع modification
        $this->detectModificationType();
        
        // بررسی احراز هویت
        if (!$this->authenticate()) {
            $this->sendResponse([
                'success' => false,
                'error' => 'Authentication failed'
            ], 401);
            exit;
        }
    }
    
    /**
     * تشخیص نسخه OpenCart
     */
    private function detectOCVersion() {
        if (defined('VERSION')) {
            $this->ocVersion = VERSION;
        } else {
            $this->ocVersion = '2.0.0.0';
        }
    }
    
    /**
     * آیا OpenCart 2؟
     */
    private function isOC2() {
        return version_compare($this->ocVersion, '2.0.0.0', '>=') && 
               version_compare($this->ocVersion, '3.0.0.0', '<');
    }
    
    /**
     * آیا OpenCart 3؟
     */
    private function isOC3() {
        return version_compare($this->ocVersion, '3.0.0.0', '>=') && 
               version_compare($this->ocVersion, '4.0.0.0', '<');
    }
    
    /**
     * آیا OpenCart 4؟
     */
    private function isOC4() {
        return version_compare($this->ocVersion, '4.0.0.0', '>=');
    }
    
    /**
     * تشخیص تمام مسیرها
     */
    private function detectPaths() {
        // 1. تشخیص Admin Path
        $this->detectAdminPath();
        
        // 2. تشخیص Modification Path
        $this->detectModificationPath();
        
        // 3. تشخیص VQMod Path
        $this->detectVQModPath();
    }
    
    /**
     * تشخیص مسیر Admin
     */
    private function detectAdminPath() {
        // اولویت 1: از constant استفاده کن
        if (defined('DIR_ADMIN')) {
            $this->adminPath = DIR_ADMIN;
            return;
        }
        
        // اولویت 2: محاسبه relative به catalog
        $catalogPath = DIR_APPLICATION;
        
        if ($this->isOC4()) {
            // OpenCart 4: admin در admin/ هست
            $this->adminPath = defined('DIR_OPENCART') ? 
                              DIR_OPENCART . 'admin/' : 
                              dirname(dirname($catalogPath)) . '/admin/';
        } else {
            // OpenCart 2 & 3
            $this->adminPath = dirname($catalogPath) . '/admin/';
        }
        
        // بررسی وجود
        if (!is_dir($this->adminPath)) {
            // جستجوی دستی
            $rootPath = $this->isOC4() ? 
                       (defined('DIR_OPENCART') ? DIR_OPENCART : dirname(dirname($catalogPath))) :
                       dirname($catalogPath);
            
            $possibleNames = ['admin', 'administrator', 'backend', 'control'];
            
            foreach ($possibleNames as $name) {
                $testPath = $rootPath . '/' . $name . '/';
                if (is_dir($testPath) && file_exists($testPath . 'index.php')) {
                    $this->adminPath = $testPath;
                    return;
                }
            }
        }
    }
    
    /**
     * تشخیص مسیر Modification (OCMOD)
     */
    private function detectModificationPath() {
        $paths = [];
        
        if ($this->isOC4()) {
            // OpenCart 4
            $paths[] = DIR_STORAGE . 'modification/';
            $paths[] = DIR_SYSTEM . 'storage/modification/';
        } elseif ($this->isOC3()) {
            // OpenCart 3
            if (defined('DIR_STORAGE')) {
                $paths[] = DIR_STORAGE . 'modification/';
            }
            $paths[] = DIR_SYSTEM . 'storage/modification/';
            $paths[] = dirname(DIR_APPLICATION) . '/system/storage/modification/';
        } else {
            // OpenCart 2
            if (defined('DIR_MODIFICATION')) {
                $paths[] = DIR_MODIFICATION;
            }
            $paths[] = DIR_SYSTEM . 'modification/';
            $paths[] = DIR_SYSTEM . 'storage/modification/';
        }
        
        // پیدا کردن اولین مسیر موجود
        foreach ($paths as $path) {
            if (is_dir($path)) {
                $this->modificationPath = $path;
                return;
            }
        }
        
        // اگر هیچکدوم نبود، اولی رو انتخاب کن
        $this->modificationPath = $paths[0];
    }
    
    /**
     * تشخیص مسیر VQMod
     */
    private function detectVQModPath() {
        $rootPath = $this->isOC4() ? 
                   (defined('DIR_OPENCART') ? DIR_OPENCART : dirname(dirname(DIR_APPLICATION))) :
                   dirname(DIR_APPLICATION);
        
        $possiblePaths = [
            $rootPath . '/vqmod/vqcache/',
            $rootPath . '/vqmod/vqcache/admin/',
            dirname($rootPath) . '/vqmod/vqcache/',
        ];
        
        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                $this->vqmodPath = $path;
                return;
            }
        }
        
        $this->vqmodPath = $possiblePaths[0];
    }
    
    /**
     * تشخیص نوع modification system
     */
    private function detectModificationType() {
        // اولویت 1: VQMod
        if ($this->isVQModActive()) {
            $this->modificationType = 'vqmod';
            return;
        }
        
        // اولویت 2: OCMOD
        if ($this->isOCModActive()) {
            $this->modificationType = 'ocmod';
            return;
        }
        
        // اولویت 3: عادی
        $this->modificationType = 'default';
    }
    
    /**
     * چک کردن VQMod
     */
    private function isVQModActive() {
        // روش 1: کلاس VQMod
        if (class_exists('VQMod')) {
            return true;
        }
        
        // روش 2: فایل vqmod.php
        $rootPath = $this->isOC4() ? 
                   (defined('DIR_OPENCART') ? DIR_OPENCART : dirname(dirname(DIR_APPLICATION))) :
                   dirname(DIR_APPLICATION);
        
        $vqmodFile = $rootPath . '/vqmod/vqmod.php';
        if (file_exists($vqmodFile)) {
            return true;
        }
        
        // روش 3: پوشه vqcache با فایل
        if (is_dir($this->vqmodPath)) {
            $files = glob($this->vqmodPath . 'vq2-*.php');
            if (!empty($files)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * چک کردن OCMOD
     */
    private function isOCModActive() {
        // روش 1: چک کردن جدول modification
        try {
            $query = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "modification'");
            if ($query->num_rows) {
                $checkQuery = $this->db->query("SELECT COUNT(*) as total FROM " . DB_PREFIX . "modification WHERE status = 1");
                if ($checkQuery->row['total'] > 0) {
                    return true;
                }
            }
        } catch (Exception $e) {
            // جدول وجود نداره یا خطا
        }
        
        // روش 2: چک کردن فایل‌های modification
        if (is_dir($this->modificationPath)) {
            // چک کردن admin
            $adminModPath = $this->modificationPath . 'admin/';
            if (is_dir($adminModPath)) {
                $files = array_merge(
                    glob($adminModPath . 'controller/*/*.php'),
                    glob($adminModPath . 'model/*/*.php')
                );
                if (!empty($files)) {
                    return true;
                }
            }
            
            // چک کردن catalog
            $catalogModPath = $this->modificationPath . 'catalog/';
            if (is_dir($catalogModPath)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * دریافت مسیر صحیح فایل
     */
    protected function getCorrectFilePath($route, $type = 'controller') {
        $foundPaths = [];
        $searchedPaths = [];
        
        if ($type == 'controller') {
            $relativePath = 'controller/' . $route . '.php';
        } elseif ($type == 'model') {
            $relativePath = 'model/' . $route . '.php';
        } else {
            $relativePath = $route;
        }
        
        // ==== اولویت 1: VQMod ====
        if ($this->modificationType == 'vqmod' || $this->isVQModActive()) {
            $vqmodFiles = $this->getVQModPaths($relativePath);
            foreach ($vqmodFiles as $vqFile) {
                $searchedPaths[] = $vqFile;
                if (file_exists($vqFile)) {
                    $foundPaths[] = $vqFile;
                }
            }
        }
        
        // ==== اولویت 2: OCMOD ====
        if (($this->modificationType == 'ocmod' || $this->isOCModActive()) && empty($foundPaths)) {
            $ocmodFiles = $this->getOCModPaths($relativePath);
            foreach ($ocmodFiles as $ocFile) {
                $searchedPaths[] = $ocFile;
                if (file_exists($ocFile)) {
                    $foundPaths[] = $ocFile;
                }
            }
        }
        
        // ==== اولویت 3: فایل اصلی ====
        $defaultPath = $this->adminPath . $relativePath;
        $searchedPaths[] = $defaultPath;
        if (file_exists($defaultPath)) {
            $foundPaths[] = $defaultPath;
        }
        
        // Debug mode
        if (empty($foundPaths) && defined('DEBUG') && DEBUG) {
            error_log("API Base: No file found for $route ($type)");
            error_log("Searched paths: " . print_r($searchedPaths, true));
        }
        
        return !empty($foundPaths) ? $foundPaths[0] : $defaultPath;
    }
    
    /**
     * دریافت مسیرهای احتمالی VQMod
     */
    private function getVQModPaths($relativePath) {
        $paths = [];
        
        // فرمت‌های مختلف VQMod
        $formats = [
            'vq2-admin_' . str_replace(['/', '.php'], ['_', ''], $relativePath) . '.php',
            'vq2-admin_' . str_replace('/', '_', $relativePath),
            'vq2_admin_' . str_replace(['/', '.php'], ['_', ''], $relativePath) . '.php',
            'admin_' . str_replace(['/', '.php'], ['_', ''], $relativePath) . '.php',
        ];
        
        foreach ($formats as $format) {
            $paths[] = $this->vqmodPath . $format;
        }
        
        // مسیر مستقیم VQMod
        $paths[] = $this->vqmodPath . 'admin/' . $relativePath;
        
        return $paths;
    }
    
    /**
     * دریافت مسیرهای احتمالی OCMOD
     */
    private function getOCModPaths($relativePath) {
        $paths = [];
        
        // OCMOD در modification path
        $paths[] = $this->modificationPath . 'admin/' . $relativePath;
        
        // OpenCart 2 - system/modification
        if ($this->isOC2()) {
            $paths[] = DIR_SYSTEM . 'admin/' . $relativePath;
        }
        
        // OpenCart 3 - system/storage/modification
        if ($this->isOC3()) {
            $paths[] = DIR_SYSTEM . 'storage/modification/admin/' . $relativePath;
            if (defined('DIR_STORAGE')) {
                $paths[] = DIR_STORAGE . 'modification/admin/' . $relativePath;
            }
        }
        
        // OpenCart 4 - system/storage/modification
        if ($this->isOC4()) {
            $paths[] = DIR_STORAGE . 'modification/admin/' . $relativePath;
            $paths[] = DIR_SYSTEM . 'storage/modification/admin/' . $relativePath;
        }
        
        return $paths;
    }
    
    protected function authenticate() {
        // دریافت API Key از header یا query string
        $apiKey = $this->getApiKey();
        
        if (empty($apiKey)) {
            return false;
        }
        
        // بررسی در دیتابیس
        try {
            $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "api` 
                WHERE `key` = '" . $this->db->escape($apiKey) . "' 
                AND status = '1'");
            
            if ($query->num_rows) {
                $this->apiUser = $query->row;
                
                // ثبت لاگ استفاده از API
                $this->logApiUsage($query->row['api_id']);
                
                return true;
            }
        } catch (Exception $e) {
            error_log("API Authentication Error: " . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * دریافت API Key از منابع مختلف
     */
    private function getApiKey() {
        // 1. از Header
        $headers = $this->getAllHeaders();
        
        if (isset($headers['X-API-Key'])) {
            return $headers['X-API-Key'];
        }
        
        if (isset($headers['X-Api-Key'])) {
            return $headers['X-Api-Key'];
        }
        
        if (isset($headers['Authorization'])) {
            // Bearer token
            if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
                return $matches[1];
            }
        }
        
        // 2. از Query String
        if (isset($this->request->get['api_key'])) {
            return $this->request->get['api_key'];
        }
        
        // 3. از POST
        if (isset($this->request->post['api_key'])) {
            return $this->request->post['api_key'];
        }
        
        return '';
    }
    
    /**
     * دریافت تمام Headers (سازگار با تمام سرورها)
     */
    private function getAllHeaders() {
        if (function_exists('getallheaders')) {
            return getallheaders();
        }
        
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$headerName] = $value;
            }
        }
        
        return $headers;
    }
    
    /**
     * ثبت لاگ استفاده از API
     */
    private function logApiUsage($api_id) {
        try {
            // چک کردن جدول api_session
            $checkTable = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "api_session'");
            
            if ($checkTable->num_rows) {
                $this->db->query("INSERT INTO `" . DB_PREFIX . "api_session` SET 
                    api_id = '" . (int)$api_id . "', 
                    session_id = '" . $this->db->escape(session_id()) . "',
                    ip = '" . $this->db->escape($this->request->server['REMOTE_ADDR']) . "', 
                    date_added = NOW(), 
                    date_modified = NOW()");
            }
        } catch (Exception $e) {
            // اگر جدول نبود یا خطا، مهم نیست
        }
    }
    
    protected function sendResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');
        
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * بارگذاری Model از admin
     */
    protected function loadAdminModel($route) {
        $key = 'model_' . str_replace('/', '_', $route);

        // چک کردن اینکه قبلاً لود شده یا نه
        try {
            $existing = $this->registry->get($key);
            if ($existing) {
                return true;
            }
        } catch (Exception $e) {
            // Model موجود نیست
        }

        $file = $this->getCorrectFilePath($route, 'model');

        if (file_exists($file)) {
            $class = 'Model' . str_replace('/', '', $route);

            // چک کردن اینکه کلاس قبلاً define نشده
            if (!class_exists($class, false)) {
                require_once($file);
            }

            if (class_exists($class)) {
                $modelInstance = new $class($this->registry);
                $this->registry->set($key, $modelInstance);
                return true;
            }
        }

        return false;
    }
    
    /**
     * بارگذاری Controller از admin
     */
    protected function loadAdminController($route) {
        $file = $this->getCorrectFilePath($route, 'controller');
        
        if (file_exists($file)) {
            require_once($file);
            
            $class = 'Controller' . str_replace('/', '', $route);
            
            if (class_exists($class)) {
                return new $class($this->registry);
            }
        }
        
        return false;
    }
    

    /**
     * اطلاعات سیستم - برای Debug
     */
    public function info() {
        $vqmodFiles = [];
        if ($this->isVQModActive() && is_dir($this->vqmodPath)) {
            $vqmodFiles = array_slice(glob($this->vqmodPath . 'vq2-*.php'), 0, 5);
        }
        
        $ocmodFiles = [];
        if ($this->isOCModActive() && is_dir($this->modificationPath . 'admin/')) {
            $ocmodFiles = array_slice(glob($this->modificationPath . 'admin/*/*.php'), 0, 5);
        }
        
        $this->sendResponse([
            'success' => true,
            'system_info' => [
                'opencart_version' => $this->ocVersion,
                'opencart_type' => $this->isOC4() ? 'OC4' : ($this->isOC3() ? 'OC3' : 'OC2'),
                'modification_type' => $this->modificationType,
                'admin_path' => $this->adminPath,
                'modification_path' => $this->modificationPath,
                'vqmod_path' => $this->vqmodPath,
                'vqmod_active' => $this->isVQModActive(),
                'ocmod_active' => $this->isOCModActive(),
                'dir_application' => DIR_APPLICATION,
                'dir_system' => DIR_SYSTEM,
                'dir_storage' => defined('DIR_STORAGE') ? DIR_STORAGE : 'Not defined',
                'sample_vqmod_files' => array_map('basename', $vqmodFiles),
                'sample_ocmod_files' => array_map(function($f) { 
                    return str_replace($this->modificationPath, '', $f); 
                }, $ocmodFiles),
            ],
            'paths_priority' => [
                '1' => 'VQMod: ' . $this->vqmodPath,
                '2' => 'OCMOD: ' . $this->modificationPath . 'admin/',
                '3' => 'Default: ' . $this->adminPath
            ]
        ]);
    }
    
    /**
     * تست مسیر فایل خاص
     */
    public function testPath() {
        $route = isset($this->request->get['route_test']) ? $this->request->get['route_test'] : 'catalog/product';
        $type = isset($this->request->get['type']) ? $this->request->get['type'] : 'model';
        
        $filePath = $this->getCorrectFilePath($route, $type);
        $fileExists = file_exists($filePath);
        
        // جمع‌آوری تمام مسیرهای احتمالی
        $relativePath = $type . '/' . $route . '.php';
        $allPaths = array_merge(
            $this->getVQModPaths($relativePath),
            $this->getOCModPaths($relativePath),
            [$this->adminPath . $relativePath]
        );
        
        $pathsInfo = [];
        foreach ($allPaths as $path) {
            $pathsInfo[] = [
                'path' => $path,
                'exists' => file_exists($path),
                'readable' => file_exists($path) && is_readable($path)
            ];
        }
        
        $this->sendResponse([
            'success' => true,
            'test_route' => $route,
            'test_type' => $type,
            'selected_file' => $filePath,
            'file_exists' => $fileExists,
            'file_readable' => $fileExists && is_readable($filePath),
            'all_possible_paths' => $pathsInfo,
            'modification_type' => $this->modificationType
        ]);
    }

}
