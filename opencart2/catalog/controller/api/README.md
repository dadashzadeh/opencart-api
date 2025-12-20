# OpenCart Product API Documentation

[![OpenCart Version](https://img.shields.io/badge/OpenCart-2.x%20%7C%203.x-brightgreen)](https://www.opencart.com/)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

A RESTful API for managing products, attributes, attribute groups, and categories in OpenCart. This API provides comprehensive endpoints for product management operations with full authentication support.

## Table of Contents
- [Installation](#installation)
- [Authentication](#authentication)
- [API Endpoints](#api-endpoints)
  - [Products](#products)
  - [Attributes](#attributes)
  - [Attribute Groups](#attribute-groups)
  - [Categories](#categories)
  - [System](#system)
- [Response Format](#response-format)
- [Error Codes](#error-codes)
- [Debugging](#debugging)
- [Configuration](#configuration)

## Installation

1. Create the API controller file:
   ```
   /catalog/controller/api/product_api.php
   ```

2. Paste the [complete source code](Pasted_Text_1766234147780.txt) into this file

3. Add your API key to `config.php` in your OpenCart root directory:
   ```php
   define('PRODUCT_API_KEY', 'your_secure_random_key_here');
   ```

4. Verify file permissions are set correctly (typically 644 for files)

## Authentication

All requests require authentication via one of these methods:

| Method          | Example                          |
|-----------------|----------------------------------|
| Query Parameter | `?api_key=your_secure_key`       |
| HTTP Header     | `X-API-KEY: your_secure_key`     |

**Example Request:**
```bash
curl -X GET https://yourstore.com/index.php?route=api/product_api/getProduct&product_id=42&api_key=your_secure_key
```

## API Endpoints

### Base URL
```
https://yourstore.com/index.php?route=api/product_api/
```

### Products

#### Search Products
- **Method**: `GET`
- **Endpoint**: `searchProduct`
- **Parameters**:
  - `name` (required): Product name to search
  - `start` (optional): Pagination offset (default: 0)
  - `limit` (optional): Results per page (default: 20)

**Example Request:**
```
GET /index.php?route=api/product_api/searchProduct&name=iPhone&limit=5&api_key=xxx
```

**Example Response:**
```json
{
  "success": true,
  "data": [
    {
      "product_id": 42,
      "name": "iPhone 13 Pro",
      "model": "IPH13P",
      "quantity": 15,
      "status": 1
    }
  ],
  "count": 1
}
```

#### Get Product Details
- **Method**: `GET`
- **Endpoint**: `getProduct`
- **Parameters**:
  - `product_id` (required): Product ID

**Example Request:**
```
GET /index.php?route=api/product_api/getProduct&product_id=42&api_key=xxx
```

#### Update Product
- **Method**: `POST`
- **Endpoint**: `updateProduct`
- **Parameters**:
  - `product_id` (required): Product ID in URL parameters
- **Request Body** (JSON):
  ```json
  {
    "name": "Updated Product Name",
    "description": "<p>HTML description</p>",
    "quantity": 25,
    "language_id": 2,
    "attributes": [
      {
        "attribute_id": 5,
        "value": "Red"
      }
    ]
  }
  ```

### Attributes

#### Search Attributes by Key
- **Method**: `GET`
- **Endpoint**: `searchAttributeByKey`
- **Parameters**:
  - `key` (required): Attribute name to search (e.g., "color")

**Example Request:**
```
GET /index.php?route=api/product_api/searchAttributeByKey&key=Color&api_key=xxx
```

#### Search Attributes by Value
- **Method**: `GET`
- **Endpoint**: `searchAttributeByValue`
- **Parameters**:
  - `value` (required): Attribute value to search (e.g., "red")

**Example Request:**
```
GET /index.php?route=api/product_api/searchAttributeByValue&value=Red&api_key=xxx
```

#### Get Attribute
- **Method**: `GET`
- **Endpoint**: `getAttribute`
- **Parameters**:
  - `attribute_id` (required)

### Attribute Groups

#### Search Attribute Groups
- **Method**: `GET`
- **Endpoint**: `searchAttributeGroup`
- **Parameters**:
  - `name` (optional): Group name filter

#### Add Attribute Group
- **Method**: `POST`
- **Endpoint**: `addAttributeGroup`
- **Request Body** (JSON):
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

### Categories

#### Get All Categories
- **Method**: `GET`
- **Endpoint**: `getAllCategories`
- **Parameters**:
  - `start` (optional): Pagination offset
  - `limit` (optional): Results per page

#### Search Categories
- **Method**: `GET`
- **Endpoint**: `searchCategories`
- **Parameters**:
  - `name` (required): Category name to search

### System

#### API Documentation
- **Method**: `GET`
- **Endpoint**: `index`
- **Description**: Returns complete API documentation in JSON format

#### Debug Information
- **Method**: `GET`
- **Endpoint**: `debugInfo`
- **Description**: Returns system information and path detection details

**Example Response:**
```json
{
  "success": true,
  "opencart_version": "2.3.0.2",
  "detected_version": 2,
  "detected_model_path": "/var/www/storage/modification/admin/model/catalog/",
  "all_searched_paths": [
    {
      "path": "/var/www/storage/modification/admin/model/catalog/",
      "exists": true,
      "is_detected": true
    }
  ]
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

## Error Codes

| Code | Description                     |
|------|---------------------------------|
| 200  | OK - Request successful         |
| 201  | Created - Resource created      |
| 400  | Bad Request - Invalid parameters|
| 401  | Unauthorized - Invalid API key  |
| 404  | Not Found - Resource not found  |
| 405  | Method Not Allowed              |
| 500  | Internal Server Error           |

## Debugging

If you encounter path detection issues (common in modified OpenCart installations):

1. Access the debug endpoint:
   ```
   GET /index.php?route=api/product_api/debugInfo&api_key=xxx
   ```

2. Review the `detected_model_path` and `all_searched_paths` in the response

3. Verify file permissions for model files in your storage directory

4. Check your OpenCart version compatibility:
   - OpenCart 2.x: Uses `token` parameter
   - OpenCart 3.x+: Uses `user_token` parameter

*For complete endpoint details, make a GET request to the API documentation endpoint.*
