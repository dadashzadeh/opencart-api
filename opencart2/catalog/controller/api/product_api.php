<?php
/**
 * Product API Controller
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
        $validApiKey = defined('PRODUCT_API_KEY') ? PRODUCT_API_KEY : 'your_secure_key';
        
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
            'version' => '1.0.0',
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
    
    /**
     * Update product information
     * POST: /index.php?route=api/product_api/updateProduct&product_id=123&api_key=xxx
     * Body: JSON with product data
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
            
            // Merge with existing product data
            $data = array_merge($existingProduct, $jsonData);
            
            // Get existing descriptions if not provided
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
            
            // Update related products if provided
            if (isset($jsonData['product_related'])) {
                $data['product_related'] = $jsonData['product_related'];
            } else if (!isset($data['product_related'])) {
                $data['product_related'] = $this->adminProductModel->getProductRelated($productId);
            }
            
            // Update attributes if provided
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
            
            // Preserve other data if not provided
            if (!isset($data['product_store'])) {
                $data['product_store'] = $this->adminProductModel->getProductStores($productId);
            }
            if (!isset($data['product_category'])) {
                $data['product_category'] = $this->adminProductModel->getProductCategories($productId);
            }
            if (!isset($data['product_filter'])) {
                $data['product_filter'] = $this->adminProductModel->getProductFilters($productId);
            }
            if (!isset($data['product_download'])) {
                $data['product_download'] = $this->adminProductModel->getProductDownloads($productId);
            }
            if (!isset($data['product_layout'])) {
                $data['product_layout'] = $this->adminProductModel->getProductLayouts($productId);
            }
            if (!isset($data['product_image'])) {
                $data['product_image'] = $this->adminProductModel->getProductImages($productId);
            }
            
            // Update product using admin model
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


