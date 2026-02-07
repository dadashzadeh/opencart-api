<?php
require_once(DIR_APPLICATION . 'controller/api/base.php');

class ControllerApiDynamic extends ControllerApiBase {
    
    // لیست dependency ها
    private $modelDependencies = [
        'sale/order' => [
            'marketing/affiliate',
            'marketing/marketing',
            'customer/customer',
            'customer/customer_group',
            'localisation/order_status',
            'localisation/country',
            'localisation/zone',
            'localisation/currency',
            'localisation/language',
            'setting/setting'
        ],
        'catalog/product' => [
            'catalog/category',
            'catalog/manufacturer',
            'catalog/option',
            'catalog/filter',
            'localisation/stock_status',
            'localisation/tax_class',
            'localisation/weight_class',
            'localisation/length_class',
            'tool/image'
        ],
        'customer/customer' => [
            'customer/customer_group',
            'marketing/affiliate',
            'localisation/country',
            'localisation/zone'
        ],
        'catalog/category' => [
            'catalog/filter',
            'setting/store',
            'tool/image'
        ]
    ];
    
    public function call() {
        try {
            $module = isset($this->request->get['module']) ? 
                     $this->request->get['module'] : '';
            $method = isset($this->request->get['method']) ? 
                     $this->request->get['method'] : '';
            
            if (empty($module) || empty($method)) {
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Module and method are required',
                    'usage' => 'GET /api/dynamic/call?module=sale/order&method=getTotalOrders&api_key=XXX'
                ], 400);
                return;
            }
            
            // 🔥 لود dependency ها قبل از model اصلی
            $this->loadModelDependencies($module);
            
            // بارگذاری model اصلی
            if (!$this->loadAdminModel($module)) {
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Model not found: ' . $module,
                    'modification_type' => $this->modificationType,
                    'searched_file' => $this->getCorrectFilePath($module, 'model')
                ], 404);
                return;
            }
            
            // دریافت model از registry
            $modelKey = 'model_' . str_replace('/', '_', $module);
            $modelObject = $this->registry->get($modelKey);
            
            // چک کردن وجود model
            if (!$modelObject) {
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Model not loaded properly',
                    'model_key' => $modelKey
                ], 500);
                return;
            }
            
            // چک کردن وجود method
            if (!method_exists($modelObject, $method)) {
                $availableMethods = get_class_methods($modelObject);
                
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Method not found: ' . $method,
                    'available_methods' => $availableMethods,
                    'suggestion' => $this->findSimilarMethod($method, $availableMethods)
                ], 404);
                return;
            }
            
            // دریافت پارامترها
            $params = $this->getMethodParams();
            
            // فراخوانی متد
            try {
                if (is_array($params) && !empty($params)) {
                    $result = call_user_func_array(
                        [$modelObject, $method], 
                        $params
                    );
                } else {
                    $result = $modelObject->$method();
                }
                
                $this->sendResponse([
                    'success' => true,
                    'result' => $result,
                    'modification_type' => $this->modificationType,
                    'model' => $module,
                    'method' => $method,
                    'params_received' => $params
                ]);
            } catch (Exception $e) {
                $this->sendResponse([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'model' => $module,
                    'method' => $method,
                    'params_sent' => $params,
                    'trace' => (defined('DEBUG') && DEBUG) ? $e->getTraceAsString() : null
                ], 500);
            }
            
        } catch (Exception $e) {
            $this->sendResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => (defined('DEBUG') && DEBUG) ? $e->getTraceAsString() : null
            ], 500);
        }
    }
    
    /**
     * 🔥 لود کردن تمام dependency های یک model
     */
    private function loadModelDependencies($module) {
        if (!isset($this->modelDependencies[$module])) {
            return;
        }
        
        foreach ($this->modelDependencies[$module] as $depModel) {
            try {
                // سعی در لود - اگر نشد، ادامه بده
                $this->loadAdminModel($depModel);
            } catch (Exception $e) {
                // Log کن ولی متوقف نشو
                error_log("Dependency load failed: $depModel - " . $e->getMessage());
            }
        }
    }
    
    /**
     * دریافت پارامترها از تمام منابع (نسخه اصلاح شده)
     */
    private function getMethodParams() {
        $params = [];
        
        // 1️⃣ دریافت JSON Body (اولویت اول)
        $jsonInput = json_decode(file_get_contents('php://input'), true);
        
        // 2️⃣ دریافت پارامترهای مستقیم از URL (مثل product_id=53)
        $urlParams = $this->extractDirectParams();
        
        // 3️⃣ تشخیص نوع متد
        $method = isset($this->request->get['method']) ? $this->request->get['method'] : '';
        
        // 🔥 برای متدهای edit/add که نیاز به $data دارند
        if (preg_match('/(edit|add|update|insert|create)/i', $method)) {
            // اگر JSON body داریم
            if ($jsonInput && !empty($jsonInput)) {
                // اگر پارامتر URL هم داشتیم (مثل product_id)
                if (!empty($urlParams)) {
                    // حالت 1: [$product_id, $data]
                    $params = array_merge($urlParams, [$jsonInput]);
                } else {
                    // حالت 2: فقط [$data]
                    $params = [$jsonInput];
                }
            } else {
                // فقط پارامترهای URL
                $params = $urlParams;
            }
        }
        // 🔥 برای متدهای get که فقط ID می‌خوان
        else {
            // اولویت با params[] در JSON
            if ($jsonInput && isset($jsonInput['params'])) {
                $params = $jsonInput['params'];
            }
            // اولویت دوم: پارامترهای URL
            elseif (!empty($urlParams)) {
                $params = $urlParams;
            }
        }
        
        return $params;
    }
    
    /**
     * استخراج پارامترهای مستقیم از URL (بدون تغییر)
     */
    private function extractDirectParams() {
        $params = [];
        $reserved = ['route', 'module', 'method', 'api_key'];
        
        foreach ($this->request->get as $key => $value) {
            if (!in_array($key, $reserved)) {
                $params[] = $value;
            }
        }
        
        return $params;
    }
    
    
    /**
     * پیشنهاد متد مشابه
     */
    private function findSimilarMethod($needle, $haystack) {
        $needle = strtolower($needle);
        foreach ($haystack as $method) {
            if (stripos($method, $needle) !== false || 
                stripos($needle, strtolower($method)) !== false) {
                return $method;
            }
        }
        return null;
    }
    
    /**
     * لیست متدها
     */
    public function methods() {
        try {
            $module = isset($this->request->get['module']) ? 
                     $this->request->get['module'] : '';
            
            if (empty($module)) {
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Module parameter is required'
                ], 400);
                return;
            }
            
            // لود dependencies
            $this->loadModelDependencies($module);
            
            if (!$this->loadAdminModel($module)) {
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Model not found: ' . $module
                ], 404);
                return;
            }
            
            $modelKey = 'model_' . str_replace('/', '_', $module);
            $modelObject = $this->registry->get($modelKey);
            
            if (!$modelObject) {
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Model not loaded'
                ], 500);
                return;
            }
            
            $methods = get_class_methods($modelObject);
            $publicMethods = [];
            $reflection = new ReflectionClass($modelObject);
            
            foreach ($methods as $method) {
                $methodReflection = $reflection->getMethod($method);
                if ($methodReflection->isPublic() && !$methodReflection->isConstructor()) {
                    $params = [];
                    foreach ($methodReflection->getParameters() as $param) {
                        $paramInfo = [
                            'name' => $param->getName(),
                            'required' => !$param->isOptional()
                        ];
                        
                        if ($param->isOptional()) {
                            try {
                                $paramInfo['default'] = $param->getDefaultValue();
                            } catch (Exception $e) {
                                $paramInfo['default'] = null;
                            }
                        }
                        
                        $params[] = $paramInfo;
                    }
                    
                    $publicMethods[] = [
                        'name' => $method,
                        'parameters' => $params
                    ];
                }
            }
            
            $this->sendResponse([
                'success' => true,
                'module' => $module,
                'methods' => $publicMethods,
                'total_methods' => count($publicMethods)
            ]);
            
        } catch (Exception $e) {
            $this->sendResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 🔍 بررسی دقیق یک متد و استخراج فیلدهای مورد نیاز از کد
     */
    public function inspect() {
        try {
            $module = isset($this->request->get['module']) ? 
                     $this->request->get['module'] : '';
            $method = isset($this->request->get['method']) ? 
                     $this->request->get['method'] : '';

            if (empty($module) || empty($method)) {
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Module and method are required',
                    'usage' => 'GET /api/dynamic/inspect?module=catalog/category&method=addCategory&api_key=XXX'
                ], 400);
                return;
            }

            // لود dependencies
            $this->loadModelDependencies($module);

            // لود model
            if (!$this->loadAdminModel($module)) {
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Model not found: ' . $module
                ], 404);
                return;
            }

            $modelKey = 'model_' . str_replace('/', '_', $module);
            $modelObject = $this->registry->get($modelKey);

            if (!$modelObject) {
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Model not loaded'
                ], 500);
                return;
            }

            // چک کردن متد
            if (!method_exists($modelObject, $method)) {
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Method not found: ' . $method
                ], 404);
                return;
            }

            // استخراج اطلاعات متد
            $reflection = new ReflectionMethod($modelObject, $method);

            // دریافت کد متد
            $filename = $reflection->getFileName();
            $startLine = $reflection->getStartLine();
            $endLine = $reflection->getEndLine();
            $length = $endLine - $startLine;

            $source = file($filename);
            $methodCode = implode("", array_slice($source, $startLine - 1, $length + 1));

            // تحلیل کد و استخراج فیلدها
            $fields = $this->extractFieldsFromCode($methodCode);

            // ایجاد نمونه JSON بر اساس تحلیل کد
            $exampleData = $this->buildExampleFromFields($fields);

            $this->sendResponse([
                'success' => true,
                'module' => $module,
                'method' => $method,
                'file_path' => $filename,
                'line_numbers' => [
                    'start' => $startLine,
                    'end' => $endLine
                ],
                'parameters' => $this->getMethodParametersInfo($reflection),
                'detected_fields' => $fields,
                'example_json' => $exampleData,
                'raw_code' => $methodCode
            ]);

        } catch (Exception $e) {
            $this->sendResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => (defined('DEBUG') && DEBUG) ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    /**
     * استخراج فیلدها از کد متد
     */
    private function extractFieldsFromCode($code) {
        $fields = [
            'required' => [],
            'optional' => [],
            'arrays' => [],
            'nested' => []
        ];

        // حذف کامنت‌ها برای تحلیل بهتر
        $code = preg_replace('/\/\*.*?\*\//s', '', $code);
        $code = preg_replace('/\/\/.*$/m', '', $code);

        // ==== استخراج تمام $data['field'] ====
        preg_match_all("/\\\$data\['([^']+)'\]/", $code, $allDataFields);
        $allFields = array_unique($allDataFields[1]);

        // ==== تشخیص فیلدهای Optional (با isset یا !empty) ====
        preg_match_all("/(?:isset|!empty)\(\s*\\\$data\['([^']+)'\]\s*\)/", $code, $optionalFields);
        $optionalList = array_unique($optionalFields[1]);

        // ==== تشخیص فیلدهای Array (با foreach) ====
        preg_match_all("/foreach\s*\(\s*\\\$data\['([^']+)'\]\s+as/", $code, $arrayFields);
        $arrayList = array_unique($arrayFields[1]);

        // ==== تشخیص فیلدهای Nested داخل foreach ====
        // الگو: foreach ($data['category_description'] as $language_id => $value)
        //        $value['name']
        preg_match_all(
            "/foreach\s*\(\s*\\\$data\['([^']+)'\]\s+as\s+[^\)]+\s*\)\s*\{([^\}]*)\}/s", 
            $code, 
            $foreachBlocks, 
            PREG_SET_ORDER
        );

        foreach ($foreachBlocks as $block) {
            $parentField = $block[1];
            $foreachContent = $block[2];

            // پیدا کردن $value['field'] یا $item['field']
            preg_match_all("/\\\$(?:value|item|row)\['([^']+)'\]/", $foreachContent, $nestedMatches);

            if (!empty($nestedMatches[1])) {
                $fields['nested'][$parentField] = array_unique($nestedMatches[1]);
            }
        }

        // ==== دسته‌بندی فیلدها ====
        foreach ($allFields as $field) {
            // اگر array است
            if (in_array($field, $arrayList)) {
                $fields['arrays'][] = $field;
            }
            // اگر optional است
            elseif (in_array($field, $optionalList)) {
                $fields['optional'][] = $field;
            }
            // در غیر این صورت required
            else {
                // چک اضافی: اگر با isset یا !empty چک شده، optional است
                if (preg_match("/isset\(\s*\\\$data\['$field'\]\s*\)/", $code) ||
                    preg_match("/!empty\(\s*\\\$data\['$field'\]\s*\)/", $code)) {
                    $fields['optional'][] = $field;
                } else {
                    $fields['required'][] = $field;
                }
            }
        }

        // حذف تکراری‌ها
        $fields['required'] = array_values(array_unique($fields['required']));
        $fields['optional'] = array_values(array_unique($fields['optional']));
        $fields['arrays'] = array_values(array_unique($fields['arrays']));

        return $fields;
    }

    /**
     * ساخت مثال JSON از روی فیلدهای استخراج شده
     */
    private function buildExampleFromFields($fields) {
        $example = [];

        // فیلدهای required
        foreach ($fields['required'] as $field) {
            $example[$field] = $this->guessFieldValue($field);
        }

        // فیلدهای optional (اضافه می‌کنیم برای کامل بودن مثال)
        foreach ($fields['optional'] as $field) {
            $example[$field] = $this->guessFieldValue($field);
        }

        // فیلدهای array
        foreach ($fields['arrays'] as $field) {
            // اگر nested داره
            if (isset($fields['nested'][$field])) {
                $nestedExample = [];
                foreach ($fields['nested'][$field] as $nestedField) {
                    $nestedExample[$nestedField] = $this->guessFieldValue($nestedField);
                }

                // برای multi-language یا multi-store از key عددی استفاده کن
                if (strpos($field, 'description') !== false || 
                    strpos($field, 'seo_url') !== false) {
                    $example[$field] = [1 => $nestedExample];
                } else {
                    $example[$field] = [$nestedExample];
                }
            } else {
                // آرایه ساده
                $example[$field] = [];
            }
        }

        return $example;
    }

    /**
     * حدس زدن مقدار فیلد بر اساس نام
     */
    private function guessFieldValue($fieldName) {
        $fieldLower = strtolower($fieldName);

        // شناسایی ID ها
        if (strpos($fieldLower, '_id') !== false) {
            if (strpos($fieldLower, 'parent') !== false) return 0;
            if (strpos($fieldLower, 'customer_group') !== false) return 1;
            if (strpos($fieldLower, 'language') !== false) return 1;
            if (strpos($fieldLower, 'store') !== false) return 0;
            if (strpos($fieldLower, 'stock_status') !== false) return 5;
            if (strpos($fieldLower, 'order_status') !== false) return 1;
            return 0;
        }

        // وضعیت و مرتب‌سازی
        if ($fieldLower === 'status') return 1;
        if ($fieldLower === 'sort_order') return 0;
        if ($fieldLower === 'top') return 1;
        if ($fieldLower === 'column') return 1;

        // اعداد
        if (strpos($fieldLower, 'quantity') !== false) return 100;
        if (strpos($fieldLower, 'minimum') !== false) return 1;
        if (strpos($fieldLower, 'subtract') !== false) return 1;
        if (strpos($fieldLower, 'shipping') !== false) return 1;
        if (strpos($fieldLower, 'points') !== false) return 0;

        // قیمت و وزن
        if (strpos($fieldLower, 'price') !== false) return '99.99';
        if (strpos($fieldLower, 'weight') !== false) return '1.00';
        if (strpos($fieldLower, 'length') !== false) return '0';
        if (strpos($fieldLower, 'width') !== false) return '0';
        if (strpos($fieldLower, 'height') !== false) return '0';

        // تاریخ
        if (strpos($fieldLower, 'date') !== false) {
            return date('Y-m-d');
        }

        // تصویر
        if (strpos($fieldLower, 'image') !== false) {
            return 'catalog/demo/image.jpg';
        }

        // ایمیل
        if (strpos($fieldLower, 'email') !== false) {
            return 'example@email.com';
        }

        // تلفن
        if (strpos($fieldLower, 'telephone') !== false || 
            strpos($fieldLower, 'phone') !== false) {
            return '09123456789';
        }

        // رمز عبور
        if (strpos($fieldLower, 'password') !== false) {
            return 'password123';
        }

        // کد و مدل
        if (strpos($fieldLower, 'model') !== false) {
            return 'PROD-' . rand(100, 999);
        }
        if (strpos($fieldLower, 'sku') !== false) return '';
        if (strpos($fieldLower, 'upc') !== false) return '';
        if (strpos($fieldLower, 'ean') !== false) return '';
        if (strpos($fieldLower, 'isbn') !== false) return '';
        if (strpos($fieldLower, 'mpn') !== false) return '';

        // متن‌ها
        if (strpos($fieldLower, 'name') !== false) {
            return 'نام';
        }
        if (strpos($fieldLower, 'title') !== false) {
            return 'عنوان';
        }
        if (strpos($fieldLower, 'description') !== false) {
            return '<p>توضیحات</p>';
        }
        if (strpos($fieldLower, 'meta_title') !== false) {
            return 'عنوان متا';
        }
        if (strpos($fieldLower, 'meta_description') !== false) {
            return 'توضیحات متا';
        }
        if (strpos($fieldLower, 'meta_keyword') !== false) {
            return 'کلمات کلیدی';
        }
        if (strpos($fieldLower, 'tag') !== false) {
            return 'برچسب';
        }
        if (strpos($fieldLower, 'keyword') !== false) {
            return 'keyword-url';
        }
        if (strpos($fieldLower, 'comment') !== false) {
            return 'نظر';
        }

        // نام‌ها
        if (strpos($fieldLower, 'firstname') !== false) {
            return 'محمد';
        }
        if (strpos($fieldLower, 'lastname') !== false) {
            return 'رضایی';
        }

        // آدرس
        if (strpos($fieldLower, 'address') !== false) {
            return 'تهران، خیابان ولیعصر';
        }
        if (strpos($fieldLower, 'city') !== false) {
            return 'تهران';
        }
        if (strpos($fieldLower, 'postcode') !== false) {
            return '1234567890';
        }
        if (strpos($fieldLower, 'location') !== false) {
            return '';
        }

        // بولین
        if (strpos($fieldLower, 'newsletter') !== false) return 0;
        if (strpos($fieldLower, 'safe') !== false) return 0;
        if (strpos($fieldLower, 'notify') !== false) return false;

        // پیش‌فرض
        return '';
    }

    /**
     * دریافت اطلاعات پارامترهای متد
     */
    private function getMethodParametersInfo($reflection) {
        $params = [];
        foreach ($reflection->getParameters() as $param) {
            $paramInfo = [
                'name' => $param->getName(),
                'required' => !$param->isOptional(),
                'type' => 'mixed'
            ];

            if ($param->isOptional()) {
                try {
                    $paramInfo['default'] = $param->getDefaultValue();
                } catch (Exception $e) {
                    $paramInfo['default'] = null;
                }
            }

            $params[] = $paramInfo;
        }
        return $params;
    }

}
