<?php
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
        $this->oc_version = (int)substr(VERSION, 0, 1);
        
        // Set JSON response header
        header('Content-Type: application/json; charset=utf-8');
        
        // Load admin models
        $this->loadAdminModels();
    }
    
    /**
     * Auto-detect and load admin models from any storage location
     */
    private function loadAdminModels() {
        $modelPath = $this->findAdminModelPath();
        
        if (empty($modelPath)) {
            $this->sendResponse(array(
                'success' => false,
                'error' => 'Admin model files not found. Searched in all possible locations.',
                'searched_paths' => $this->getSearchPaths()
            ), 500);
            exit;
        }
        
        // Include admin model files
        require_once($modelPath . 'product.php');
        require_once($modelPath . 'attribute.php');
        require_once($modelPath . 'attribute_group.php');
        
        // Create instances
        $this->adminProductModel = new ModelCatalogProduct($this->registry);
        $this->adminAttributeModel = new ModelCatalogAttribute($this->registry);
        $this->adminAttributeGroupModel = new ModelCatalogAttributeGroup($this->registry);
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
     * @return array List of paths to search
     */
    private function getSearchPaths() {
        $paths = array();
        
        // Get base directory (opencart root)
        $baseDir = DIR_APPLICATION . '../';
        
        // 1. Check if DIR_STORAGE is defined in config (OpenCart 3.x+)
        if (defined('DIR_STORAGE')) {
            $paths[] = DIR_STORAGE . 'modification/admin/model/catalog/';
        }
        
        // 2. Common storage locations relative to catalog
        $storageLocations = array(
            'system/storage/modification/admin/model/catalog/',
            'storage/modification/admin/model/catalog/',
            '../storage/modification/admin/model/catalog/',
            '../../storage/modification/admin/model/catalog/',
            '../system/storage/modification/admin/model/catalog/',
        );
        
        foreach ($storageLocations as $location) {
            $paths[] = $baseDir . $location;
        }
        
        // 3. Direct admin folder (fallback if no modifications)
        $paths[] = $baseDir . 'admin/model/catalog/';
        
        // 4. Search in parent directories (for custom installations)
        $currentDir = realpath(DIR_APPLICATION);
        $maxLevels = 5; // Search up to 5 levels up
        
        for ($i = 0; $i < $maxLevels; $i++) {
            $currentDir = dirname($currentDir);
            
            // Check storage in current level
            $paths[] = $currentDir . '/storage/modification/admin/model/catalog/';
            $paths[] = $currentDir . '/system/storage/modification/admin/model/catalog/';
            
            // Stop if we reach root
            if ($currentDir == dirname($currentDir)) {
                break;
            }
        }
        
        // 5. Absolute path search (if OpenCart is not in default location)
        if (defined('DIR_SYSTEM')) {
            $systemDir = rtrim(DIR_SYSTEM, '/');
            $paths[] = $systemDir . '/storage/modification/admin/model/catalog/';
        }
        
        // Remove duplicates and normalize paths
        $paths = array_unique($paths);
        $normalizedPaths = array();
        
        foreach ($paths as $path) {
            $realPath = realpath($path);
            if ($realPath !== false) {
                $normalizedPaths[] = $realPath . '/';
            } else {
                $normalizedPaths[] = $path;
            }
        }
        
        return array_unique($normalizedPaths);
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
    private function authenticate() {
        $apiKey = '';
        
        // Get API key from GET parameter or HTTP header
        if (isset($this->request->get['api_key'])) {
            $apiKey = $this->request->get['api_key'];
        } elseif (isset($this->request->server['HTTP_X_API_KEY'])) {
            $apiKey = $this->request->server['HTTP_X_API_KEY'];
        }

        // Validate API key (store in config.php: define('PRODUCT_API_KEY', 'your_secure_key');)
        $validApiKey = defined('PRODUCT_API_KEY') ? PRODUCT_API_KEY : 'sds!dwd3dsSFSd111!';
        
        if ($apiKey !== $validApiKey) {
            $this->sendResponse(array(
                'success' => false,
                'error' => 'Unauthorized: Invalid or missing API key'
            ), 401);
            exit;
        }
        
        return true;
    }
    
    /**
     * Send JSON response
     * @param array $data Response data
     * @param int $statusCode HTTP status code
     */
    private function sendResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
    
    // ==================== IMAGE UPLOAD OPERATIONS ====================
    
    /**
     * Upload image with support for all formats (JPG, PNG, GIF, WebP, BMP)
     * Works with PHP 5.6+ and OpenCart 2.x/3.x
     * 
     * ✅ NEW: Supports custom filenames - preserves original or uses provided name
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
     * ✅ UPDATED: Upload file from $_FILES array with custom filename support
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
        
        // ✅ NEW: Determine final filename
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
     * ✅ NEW: Determine final filename based on priority
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
     * ✅ NEW: Sanitize filename - remove invalid characters
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
     * ✅✅✅ UPDATED: Update product information with COMPLETE field preservation
     * POST: /index.php?route=api/product_api/updateProduct&product_id=123&api_key=xxx
     * Body: JSON with product data
     * 
     * ✅ VERSION 1.1 - Enhanced with comprehensive relational data preservation
     * ✅ Preserves ALL OpenCart relational fields (options, discounts, specials, etc.)
     * ✅ Only updates fields that are explicitly provided in the request
     * ✅ Compatible with future OpenCart versions
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
            // ✅✅✅ PRESERVE ALL RELATIONAL DATA ✅✅✅
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
            
            // ========================================
            // ✅✅✅ CRITICAL FIELDS - NEWLY ADDED ✅✅✅
            // These fields were MISSING in the original code
            // ========================================
            
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
            // ✅ Fixed: Check method name and existence
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
     * ✅ OPTIONAL: Helper method to get all relational data (FUTURE-PROOF approach)
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
     * ✅ ALTERNATIVE updateProduct() using the helper method
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
     *         // ✅ Smart preservation of all relational data
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

            // ✅ بررسی نتیجه query
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

            // ✅ مطمئن شویم rows یک array است
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
}