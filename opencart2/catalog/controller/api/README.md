# OpenCart Product API Documentation

[![OpenCart Version](https://img.shields.io/badge/OpenCart-2.x%20%7C%203.x-brightgreen)](https://www.opencart.com/)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Version](https://img.shields.io/badge/Version-1.1.0-blue)](https://github.com/your-repo/opencart-api)

A comprehensive RESTful API for managing products, attributes, attribute groups, categories, and images in OpenCart. This API provides complete CRUD operations with full field preservation, image upload capabilities, and advanced search functionality.

## ✨ Features

- **Complete Product Management**: Search, get, update products with full field preservation
- **Advanced Image Upload**: Support for JPG, PNG, GIF, WebP, BMP with custom filenames
- **Attribute Management**: Full CRUD operations for attributes and attribute groups
- **Category Operations**: Search and list categories with product associations
- **Bulk Operations**: Get multiple products by IDs, get products by category
- **Smart Path Detection**: Auto-detects OpenCart admin model paths across different installations
- **Version Compatibility**: Works with OpenCart 2.x and 3.x
- **Field Preservation**: Maintains all product relationships (options, discounts, specials, etc.)

## Table of Contents
- [Installation](#installation)
- [Authentication](#authentication)
- [API Endpoints](#api-endpoints)
  - [Products](#products)
  - [Image Upload](#image-upload)
  - [Attributes](#attributes)
  - [Attribute Groups](#attribute-groups)
  - [Categories](#categories)
  - [System](#system)
- [Response Format](#response-format)
- [Error Codes](#error-codes)
- [Debugging](#debugging)
- [Configuration](#configuration)

## Installation

1. **Create the API controller file:**
   ```
   /catalog/controller/api/product_api.php
   ```

2. **Copy the complete source code** from `product_api.php` into this file

3. **Configure API key** in your OpenCart root `config.php`:
   ```php
   define('PRODUCT_API_KEY', 'your_secure_random_key_here');
   ```
   
   💡 **Tip**: Generate a secure key using: `openssl rand -hex 32`

4. **Set file permissions** (typically 644 for files, 755 for directories)

5. **Test the installation:**
   ```bash
   curl "https://yourstore.com/index.php?route=api/product_api/debugInfo&api_key=your_key"
   ```

## Authentication

All requests require authentication via one of these methods:

| Method          | Example                          | Usage                    |
|-----------------|----------------------------------|--------------------------|
| Query Parameter | `?api_key=your_secure_key`       | Simple GET requests      |
| HTTP Header     | `X-API-KEY: your_secure_key`     | POST/PUT requests        |

**Example Requests:**
```bash
# GET request with query parameter
curl "https://yourstore.com/index.php?route=api/product_api/getProduct&product_id=42&api_key=your_key"

# POST request with header
curl -X POST \
  -H "X-API-KEY: your_key" \
  -H "Content-Type: application/json" \
  -d '{"name":"Updated Product"}' \
  "https://yourstore.com/index.php?route=api/product_api/updateProduct&product_id=42"
```

## API Endpoints

### Base URL
```
https://yourstore.com/index.php?route=api/product_api/
```

### Products

#### 🔍 Search Products
- **Method**: `GET`
- **Endpoint**: `searchProduct`
- **Parameters**:
  - `name` (required): Product name to search
  - `start` (optional): Pagination offset (default: 0)
  - `limit` (optional): Results per page (default: 20)

**Example Request:**
```bash
curl "https://yourstore.com/index.php?route=api/product_api/searchProduct&name=iPhone&limit=5&api_key=xxx"
```

**Example Response:**
```json
{
  "success": true,
  "data": [
    {
      "product_id": 42,
      "name": "iPhone 15 Pro",
      "model": "IPH15P-128",
      "quantity": 50,
      "status": 1
    }
  ],
  "count": 1
}
```

#### 📋 Get Product Details
- **Method**: `GET`
- **Endpoint**: `getProduct`
- **Parameters**:
  - `product_id` (required): Product ID

**Example Request:**
```bash
curl "https://yourstore.com/index.php?route=api/product_api/getProduct&product_id=42&api_key=xxx"
```

**Example Response:**
```json
{
  "success": true,
  "data": {
    "product_id": 42,
    "model": "IPH15P-128",
    "status": 1,
    "descriptions": {
      "2": {
        "name": "iPhone 15 Pro",
        "description": "<p>Latest iPhone model</p>"
      }
    },
    "attributes": [
      {
        "attribute_id": 5,
        "language_id": 2,
        "key": "Color",
        "value": "Space Black"
      }
    ],
    "categories": [
      {
        "category_id": 25,
        "name": "Smartphones"
      }
    ]
  }
}
```

#### ✏️ Update Product
- **Method**: `POST`
- **Endpoint**: `updateProduct`
- **Parameters**:
  - `product_id` (required): Product ID in URL
- **Features**:
  - ✅ **Complete Field Preservation**: Maintains all product relationships
  - ✅ **Partial Updates**: Only updates provided fields
  - ✅ **Attribute Management**: Update product attributes
  - ✅ **Multi-language Support**: Update descriptions per language

**Request Body (JSON):**
```json
{
  "name": "Updated Product Name",
  "description": "<p>Updated HTML description</p>",
  "quantity": 25,
  "language_id": 2,
  "attributes": [
    {
      "attribute_id": 5,
      "value": "Deep Purple",
      "language_id": 2
    }
  ]
}
```

**Example Request:**
```bash
curl -X POST \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: your_key" \
  -d '{"name":"Updated iPhone","quantity":30}' \
  "https://yourstore.com/index.php?route=api/product_api/updateProduct&product_id=42"
```

#### 📦 Get Products by Category
- **Method**: `GET`
- **Endpoint**: `getProductsByCategory`
- **Parameters**:
  - `category_id` (int) OR `category_name` (string): Category identifier
  - `start` (optional): Pagination offset (default: 0)
  - `limit` (optional): Results per page (default: 100, max: 100)

**Example Requests:**
```bash
# By category ID
curl "https://yourstore.com/index.php?route=api/product_api/getProductsByCategory&category_id=25&api_key=xxx"

# By category name
curl "https://yourstore.com/index.php?route=api/product_api/getProductsByCategory&category_name=Smartphones&api_key=xxx"
```

#### 🔢 Get Multiple Products by IDs
- **Method**: `GET` or `POST`
- **Endpoint**: `getProductsByIds`
- **Parameters**:
  - `product_ids`: Comma-separated string or JSON array of product IDs (max: 100)

**Example Requests:**
```bash
# GET with comma-separated IDs
curl "https://yourstore.com/index.php?route=api/product_api/getProductsByIds&product_ids=42,43,44&api_key=xxx"

# POST with JSON array
curl -X POST \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: your_key" \
  -d '{"product_ids": [42, 43, 44]}' \
  "https://yourstore.com/index.php?route=api/product_api/getProductsByIds"
```

### Image Upload

#### 📸 Upload Image
- **Method**: `POST`
- **Endpoint**: `uploadImage`
- **Features**:
  - ✅ **Multiple Formats**: JPG, PNG, GIF, WebP, BMP
  - ✅ **Custom Filenames**: Preserve original or use custom names
  - ✅ **Auto Resize**: Optional width/height resizing
  - ✅ **Smart Deduplication**: Automatic filename conflict resolution
  - ✅ **Base64 Support**: Upload via JSON or multipart form

**Upload Methods:**

**Method 1: Multipart Form-Data**
```bash
curl -X POST \
  -H "X-API-KEY: your_key" \
  -F "image=@/path/to/image.jpg" \
  -F "filename=product-main.jpg" \
  -F "subfolder=products" \
  -F "resize_width=800" \
  "https://yourstore.com/index.php?route=api/product_api/uploadImage"
```

**Method 2: Base64 JSON**
```bash
curl -X POST \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: your_key" \
  -d '{
    "image_data": "data:image/png;base64,iVBORw0KGgo...",
    "filename": "product-image.png",
    "subfolder": "products",
    "resize_width": 800
  }' \
  "https://yourstore.com/index.php?route=api/product_api/uploadImage"
```

**Response:**
```json
{
  "success": true,
  "message": "Image uploaded successfully",
  "data": {
    "filename": "products/product-main.jpg",
    "original_name": "my-photo.jpg",
    "url": "https://yourstore.com/image/catalog/products/product-main.jpg",
    "size": 45678,
    "mime_type": "image/jpeg",
    "dimensions": {
      "width": 800,
      "height": 600
    }
  }
}
```

#### 🖼️ Update Product Image
- **Method**: `POST`
- **Endpoint**: `updateProductImage`
- **Parameters**:
  - `product_id` (required): Product ID in URL
- **Description**: Uploads new image and sets it as the product's main image

**Example Request:**
```bash
curl -X POST \
  -H "X-API-KEY: your_key" \
  -F "image=@/path/to/new-image.jpg" \
  -F "filename=iphone-15-pro.jpg" \
  "https://yourstore.com/index.php?route=api/product_api/updateProductImage&product_id=42"
```

#### 🗑️ Delete Image
- **Method**: `DELETE`
- **Endpoint**: `deleteImage`
- **Parameters**:
  - `filename` (required): Relative path to image file

**Example Request:**
```bash
curl -X DELETE \
  "https://yourstore.com/index.php?route=api/product_api/deleteImage&filename=products/old-image.jpg&api_key=xxx"
```

### Attributes

#### 🔍 Search Attributes by Key
- **Method**: `GET`
- **Endpoint**: `searchAttributeByKey`
- **Parameters**:
  - `key` (required): Attribute name to search (e.g., "Color", "Size")
- **Description**: Searches in attribute names/titles, not values

**Example Request:**
```bash
curl "https://yourstore.com/index.php?route=api/product_api/searchAttributeByKey&key=Color&api_key=xxx"
```

**Example Response:**
```json
{
  "success": true,
  "data": [
    {
      "attribute_id": 5,
      "name": "Color",
      "attribute_group_id": 2,
      "group_name": "General",
      "sort_order": 1
    },
    {
      "attribute_id": 12,
      "name": "Primary Color",
      "attribute_group_id": 2,
      "group_name": "General",
      "sort_order": 2
    }
  ],
  "count": 2
}
```

#### 🔍 Search Attributes by Value
- **Method**: `GET`
- **Endpoint**: `searchAttributeByValue`
- **Parameters**:
  - `value` (required): Attribute value to search (e.g., "Red", "Large")
- **Description**: Searches in actual attribute values assigned to products

**Example Request:**
```bash
curl "https://yourstore.com/index.php?route=api/product_api/searchAttributeByValue&value=Red&api_key=xxx"
```

**Example Response:**
```json
{
  "success": true,
  "data": [
    {
      "attribute_id": 5,
      "key": "Color",
      "value": "Red, Blue, Green",
      "attribute_group_id": 2,
      "group_name": "General",
      "product_count": 15
    }
  ],
  "count": 1
}
```

#### 📋 Get All Attributes
- **Method**: `GET`
- **Endpoint**: `getAllAttributes`
- **Parameters**:
  - `start` (optional): Pagination offset (default: 0)
  - `limit` (optional): Results per page (default: 20)

#### 📄 Get Attribute
- **Method**: `GET`
- **Endpoint**: `getAttribute`
- **Parameters**:
  - `attribute_id` (required): Attribute ID

#### ➕ Add Attribute
- **Method**: `POST`
- **Endpoint**: `addAttribute`
- **Request Body (JSON):**
```json
{
  "attribute_group_id": 2,
  "sort_order": 1,
  "attribute_description": {
    "2": {
      "name": "Screen Size"
    }
  }
}
```

#### ✏️ Update Attribute
- **Method**: `POST`
- **Endpoint**: `updateAttribute`
- **Parameters**:
  - `attribute_id` (required): Attribute ID in URL

#### 🗑️ Delete Attribute
- **Method**: `DELETE`
- **Endpoint**: `deleteAttribute`
- **Parameters**:
  - `attribute_id` (required): Attribute ID

### Attribute Groups

#### 🔍 Search Attribute Groups
- **Method**: `GET`
- **Endpoint**: `searchAttributeGroup`
- **Parameters**:
  - `name` (optional): Group name filter

**Example Request:**
```bash
curl "https://yourstore.com/index.php?route=api/product_api/searchAttributeGroup&name=Technical&api_key=xxx"
```

#### 📋 Get All Attribute Groups
- **Method**: `GET`
- **Endpoint**: `getAllAttributeGroups`
- **Parameters**:
  - `start` (optional): Pagination offset (default: 0)
  - `limit` (optional): Results per page (default: 20)

#### 📄 Get Attribute Group
- **Method**: `GET`
- **Endpoint**: `getAttributeGroup`
- **Parameters**:
  - `attribute_group_id` (required): Attribute group ID

#### ➕ Add Attribute Group
- **Method**: `POST`
- **Endpoint**: `addAttributeGroup`
- **Request Body (JSON):**
```json
{
  "sort_order": 1,
  "attribute_group_description": {
    "2": {
      "name": "Technical Specifications"
    }
  }
}
```

#### ✏️ Update Attribute Group
- **Method**: `POST`
- **Endpoint**: `updateAttributeGroup`
- **Parameters**:
  - `attribute_group_id` (required): Attribute group ID in URL

#### 🗑️ Delete Attribute Group
- **Method**: `DELETE`
- **Endpoint**: `deleteAttributeGroup`
- **Parameters**:
  - `attribute_group_id` (required): Attribute group ID

### Categories

#### 📋 Get All Categories
- **Method**: `GET`
- **Endpoint**: `getAllCategories`
- **Parameters**:
  - `start` (optional): Pagination offset (default: 0)
  - `limit` (optional): Results per page (default: 20)

**Example Request:**
```bash
curl "https://yourstore.com/index.php?route=api/product_api/getAllCategories&limit=10&api_key=xxx"
```

#### 🔍 Search Categories
- **Method**: `GET`
- **Endpoint**: `searchCategories`
- **Parameters**:
  - `name` (required): Category name to search

**Example Request:**
```bash
curl "https://yourstore.com/index.php?route=api/product_api/searchCategories&name=Electronics&api_key=xxx"
```

### System

#### 📖 API Documentation
- **Method**: `GET`
- **Endpoint**: `index`
- **Description**: Returns complete API documentation in JSON format with all endpoints and examples

**Example Request:**
```bash
curl "https://yourstore.com/index.php?route=api/product_api/index&api_key=xxx"
```

#### 🔧 Debug Information
- **Method**: `GET`
- **Endpoint**: `debugInfo`
- **Description**: Returns system information, OpenCart version, and path detection details

**Example Request:**
```bash
curl "https://yourstore.com/index.php?route=api/product_api/debugInfo&api_key=xxx"
```

**Example Response:**
```json
{
  "success": true,
  "opencart_version": "2.3.0.2",
  "detected_version": 2,
  "token_name": "token",
  "seo_table": "url_alias",
  "detected_model_path": "/var/www/storage/modification/admin/model/catalog/",
  "all_searched_paths": [
    {
      "path": "/var/www/storage/modification/admin/model/catalog/",
      "exists": true,
      "is_detected": true
    }
  ],
  "dir_application": "/var/www/catalog/",
  "dir_system": "/var/www/system/",
  "dir_storage": "/var/www/storage/"
}
```

## Response Format

### Success Response
```json
{
  "success": true,
  "data": { ... },
  "count": 1
}
```

### Error Response
```json
{
  "success": false,
  "error": "Error description"
}
```

### Pagination Response
```json
{
  "success": true,
  "data": [...],
  "count": 10,
  "total": 150,
  "start": 0,
  "limit": 10
}
```

## Error Codes

| Code | Description                     | Common Causes                    |
|------|---------------------------------|----------------------------------|
| 200  | OK - Request successful         | -                                |
| 201  | Created - Resource created      | Successful POST operations       |
| 400  | Bad Request - Invalid parameters| Missing required fields          |
| 401  | Unauthorized - Invalid API key  | Wrong or missing API key         |
| 404  | Not Found - Resource not found  | Invalid product/attribute ID     |
| 405  | Method Not Allowed              | Wrong HTTP method                |
| 500  | Internal Server Error           | Database or file system issues   |

## Debugging

### Common Issues and Solutions

#### 1. Path Detection Issues
If you encounter "Admin model files not found" errors:

1. **Check debug info:**
   ```bash
   curl "https://yourstore.com/index.php?route=api/product_api/debugInfo&api_key=xxx"
   ```

2. **Review the response:**
   - `detected_model_path`: Should point to your admin models
   - `all_searched_paths`: Shows all locations searched
   - Look for `"exists": true` in the paths

3. **Common solutions:**
   - Verify OpenCart installation structure
   - Check file permissions (755 for directories, 644 for files)
   - Ensure admin model files exist in storage/modification directory

#### 2. Image Upload Issues
- **File too large**: Check PHP `upload_max_filesize` and `post_max_size`
- **Permission denied**: Verify write permissions on `image/catalog/` directory
- **Invalid format**: Ensure file is a valid image format (JPG, PNG, GIF, WebP, BMP)

#### 3. Authentication Issues
- **401 Unauthorized**: Verify API key is correct and properly configured
- **Missing API key**: Ensure key is passed via query parameter or header

#### 4. Version Compatibility
- **OpenCart 2.x**: Uses `token` parameter, `url_alias` table
- **OpenCart 3.x+**: Uses `user_token` parameter, `seo_url` table
- The API auto-detects version and adapts accordingly

### Debug Endpoints

#### System Information
```bash
curl "https://yourstore.com/index.php?route=api/product_api/debugInfo&api_key=xxx"
```

#### API Documentation
```bash
curl "https://yourstore.com/index.php?route=api/product_api/index&api_key=xxx"
```

## Configuration

### Required Configuration

1. **API Key** (in `config.php`):
   ```php
   define('PRODUCT_API_KEY', 'your_secure_random_key_here');
   ```

2. **File Permissions**:
   - API file: `644`
   - Image directories: `755`
   - Uploaded images: `644`

### Optional Configuration

1. **Default Language ID** (modify in API file):
   ```php
   const LANGUAGE_ID = 2; // Change to your default language ID
   ```

2. **Image Upload Directory** (modify `getImageDirectory()` method):
   ```php
   return DIR_IMAGE . 'catalog/'; // Default: image/catalog/
   ```

3. **Maximum File Size** (modify in `uploadFileImage()` method):
   ```php
   $maxSize = 10 * 1024 * 1024; // Default: 10MB
   ```

## Advanced Usage

### Bulk Operations

#### Update Multiple Products
```bash
# Update product 42
curl -X POST -H "Content-Type: application/json" -H "X-API-KEY: xxx" \
  -d '{"quantity": 100}' \
  "https://yourstore.com/index.php?route=api/product_api/updateProduct&product_id=42"

# Update product 43
curl -X POST -H "Content-Type: application/json" -H "X-API-KEY: xxx" \
  -d '{"quantity": 50}' \
  "https://yourstore.com/index.php?route=api/product_api/updateProduct&product_id=43"
```

#### Batch Image Upload
```bash
# Upload multiple images with custom names
for i in {1..5}; do
  curl -X POST -H "X-API-KEY: xxx" \
    -F "image=@product-$i.jpg" \
    -F "filename=product-$i-main.jpg" \
    -F "subfolder=products" \
    "https://yourstore.com/index.php?route=api/product_api/uploadImage"
done
```

### Integration Examples

#### PHP Integration
```php
<?php
class OpenCartAPI {
    private $baseUrl;
    private $apiKey;
    
    public function __construct($baseUrl, $apiKey) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }
    
    public function searchProducts($name, $limit = 20) {
        $url = $this->baseUrl . '/index.php?route=api/product_api/searchProduct';
        $url .= '&name=' . urlencode($name) . '&limit=' . $limit . '&api_key=' . $this->apiKey;
        
        $response = file_get_contents($url);
        return json_decode($response, true);
    }
    
    public function updateProduct($productId, $data) {
        $url = $this->baseUrl . '/index.php?route=api/product_api/updateProduct&product_id=' . $productId;
        
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'X-API-KEY: ' . $this->apiKey
                ],
                'content' => json_encode($data)
            ]
        ]);
        
        $response = file_get_contents($url, false, $context);
        return json_decode($response, true);
    }
}

// Usage
$api = new OpenCartAPI('https://yourstore.com', 'your_api_key');
$products = $api->searchProducts('iPhone');
$result = $api->updateProduct(42, ['quantity' => 100]);
?>
```

#### Python Integration
```python
import requests
import json

class OpenCartAPI:
    def __init__(self, base_url, api_key):
        self.base_url = base_url.rstrip('/')
        self.api_key = api_key
        self.session = requests.Session()
        self.session.headers.update({'X-API-KEY': api_key})
    
    def search_products(self, name, limit=20):
        url = f"{self.base_url}/index.php"
        params = {
            'route': 'api/product_api/searchProduct',
            'name': name,
            'limit': limit,
            'api_key': self.api_key
        }
        response = self.session.get(url, params=params)
        return response.json()
    
    def update_product(self, product_id, data):
        url = f"{self.base_url}/index.php"
        params = {
            'route': 'api/product_api/updateProduct',
            'product_id': product_id
        }
        response = self.session.post(url, params=params, json=data)
        return response.json()

# Usage
api = OpenCartAPI('https://yourstore.com', 'your_api_key')
products = api.search_products('iPhone')
result = api.update_product(42, {'quantity': 100})
```

## Support

For issues, questions, or contributions:

1. **Check the debug endpoint** for system information
2. **Review the complete API documentation** via the index endpoint
3. **Verify your OpenCart version compatibility**
4. **Test with simple GET requests first** before complex operations