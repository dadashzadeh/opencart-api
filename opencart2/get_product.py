"""
OpenCart Product API Python Client

This module provides a comprehensive Python client for the OpenCart Product API.
It supports OpenCart versions 2.x and 3.x with automatic version detection and
compatibility handling.

Available Methods:

PRODUCTS:
- search_products(name, start, limit) - Search products by name
- get_product(product_id) - Get complete product details
- update_product(product_id, data, encode_html) - Update product information

ATTRIBUTES:
- search_attributes_by_key(key) - Search attribute definitions by name
- search_attributes_by_value(value) - Search attributes by assigned values
- get_attribute(attribute_id) - Get attribute details
- add_attribute(data, encode_html) - Create new attribute

ATTRIBUTE GROUPS:
- search_attribute_groups(name) - Search attribute groups
- add_attribute_group(data, encode_html) - Create new attribute group

CATEGORIES:
- get_all_categories(start, limit) - Get paginated category list
- search_categories(name) - Search categories by name

SYSTEM:
- get_debug_info() - Get system and API configuration details

Note: Some methods available in the PHP API are not yet implemented in this client:
- update_attribute() - Update existing attribute
- delete_attribute() - Delete attribute
- get_attribute_group() - Get attribute group details
- update_attribute_group() - Update attribute group
- delete_attribute_group() - Delete attribute group
- delete_product() - Delete product (commented out in PHP for safety)
"""

import requests
import json
import html
from typing import Dict, List, Optional, Union, Any, Tuple
import re

class OpenCartAPI:
    """
    OpenCart Product API Client for OpenCart 2.x and 3.x

    A comprehensive Python client for the OpenCart Product API that provides full CRUD operations
    for products, attributes, attribute groups, and categories. Features automatic HTML entity
    decoding/encoding, robust error handling, and JSON response cleaning.

    Features:
        - Automatic HTML entity handling in responses and requests
        - Support for OpenCart 2.x and 3.x versions
        - Comprehensive product management (search, get, update)
        - Full attribute management (CRUD operations)
        - Attribute group management (CRUD operations)
        - Category browsing and search
        - Debug information and API documentation access
        - Robust error handling with detailed error messages
        - PHP notice/warning cleanup from JSON responses

    Authentication:
        All requests require API key authentication via:
        - Query parameter: ?api_key=your_key
        - HTTP header: X-API-KEY: your_key

    Args:
        base_url (str): OpenCart base URL (e.g., 'https://yourstore.com')
        api_key (str): API authentication key (defined in OpenCart config as PRODUCT_API_KEY)
        auto_decode_html (bool): Automatically decode HTML entities in API responses (default: True)
        timeout (int): Request timeout in seconds (default: 30)

    Example:
        >>> api = OpenCartAPI(
        ...     base_url='https://yourstore.com',
        ...     api_key='your_secure_api_key',
        ...     auto_decode_html=True,
        ...     timeout=30
        ... )

        >>> # Search products
        >>> products = api.search_products('iPhone', limit=10)
        >>> print(f"Found {products['count']} products")

        >>> # Get product details
        >>> product = api.get_product(123)
        >>> print(product['data']['name'])

        >>> # Update product
        >>> api.update_product(123, {'price': '999.00', 'quantity': 50})

    Note:
        The API key should be defined in your OpenCart installation's config.php:
        define('PRODUCT_API_KEY', 'your_secure_key_here');
    """
    
    def __init__(
        self, 
        base_url: str, 
        api_key: str,
        auto_decode_html: bool = True,
        timeout: int = 30
    ):
        self.base_url = base_url.rstrip('/')
        self.api_key = api_key
        self.auto_decode_html = auto_decode_html
        self.timeout = timeout
        
        self.session = requests.Session()
        self.session.headers.update({
            'X-API-KEY': api_key,
            'Content-Type': 'application/json; charset=utf-8',
            'User-Agent': 'OpenCartAPI-Python-Client/1.0'
        })
    
    def _decode_html_in_dict(self, data: Any) -> Any:
        """
        Recursively decode HTML entities in dictionary/list structures.
        
        Args:
            data: Data to decode (dict, list, or string)
            
        Returns:
            Decoded data
        """
        if isinstance(data, dict):
            return {key: self._decode_html_in_dict(value) for key, value in data.items()}
        elif isinstance(data, list):
            return [self._decode_html_in_dict(item) for item in data]
        elif isinstance(data, str):
            return html.unescape(data)
        else:
            return data
    
    def _encode_html_in_dict(self, data: Any) -> Any:
        """
        Recursively encode HTML in dictionary/list structures.
        
        Args:
            data: Data to encode (dict, list, or string)
            
        Returns:
            Encoded data
        """
        if isinstance(data, dict):
            return {key: self._encode_html_in_dict(value) for key, value in data.items()}
        elif isinstance(data, list):
            return [self._encode_html_in_dict(item) for item in data]
        elif isinstance(data, str):
            return html.escape(data, quote=False)
        else:
            return data

    def _clean_json_response(self, text: str) -> str:
        """
        Clean PHP notices/warnings from JSON response.
        Extracts only the JSON part from response text.
        """
        # Try to find JSON object or array in the text
        # Look for the last opening brace/bracket
        json_match = re.search(r'(\{.*\}|\[.*\])\s*$', text, re.DOTALL)
        if json_match:
            return json_match.group(1)
        return text
    
    def _request(
        self, 
        endpoint: str, 
        method: str = 'GET', 
        data: Optional[Dict] = None, 
        params: Optional[Dict] = None
    ) -> Dict[str, Any]:
        """
        Make API request with error handling and HTML processing.
        
        Args:
            endpoint (str): API endpoint (without 'api/product_api/')
            method (str): HTTP method (GET/POST/DELETE)
            data (Optional[Dict]): Request body data
            params (Optional[Dict]): URL parameters
            
        Returns:
            Dict[str, Any]: API response
            
        Raises:
            requests.exceptions.HTTPError: For HTTP errors
            ValueError: For invalid responses
        """
        url = f"{self.base_url}/index.php"
        
        full_params = {'route': f'api/product_api/{endpoint}'}
        if params:
            full_params.update(params)
        
        try:
            if method == 'GET':
                response = self.session.get(url, params=full_params, timeout=self.timeout)
            elif method == 'POST':
                response = self.session.post(url, params=full_params, json=data, timeout=self.timeout)
            elif method == 'DELETE':
                response = self.session.delete(url, params=full_params, timeout=self.timeout)
            else:
                raise ValueError(f"Unsupported HTTP method: {method}")
            
            response.raise_for_status()
            
            # Clean and parse JSON response
            try:
                # First try direct parsing
                result = response.json()
            except json.JSONDecodeError:
                # If fails, try cleaning PHP notices
                cleaned_text = self._clean_json_response(response.text)
                try:
                    result = json.loads(cleaned_text)
                except json.JSONDecodeError:
                    raise ValueError(f"Invalid JSON response after cleaning: {response.text[:500]}")
            
            # Validate response structure
            if 'success' not in result:
                raise ValueError("Invalid API response format: missing 'success' field")
            
            # Automatically decode HTML entities in successful responses
            if self.auto_decode_html and result.get('success') and 'data' in result:
                result['data'] = self._decode_html_in_dict(result['data'])
            
            return result
            
        except requests.exceptions.RequestException as e:
            if hasattr(e, 'response') and e.response is not None:
                try:
                    error_data = e.response.json()
                    error_msg = error_data.get('error', str(e))
                except:
                    error_msg = e.response.text or str(e)
                raise requests.exceptions.HTTPError(
                    f"API Error ({e.response.status_code}): {error_msg}",
                    response=e.response
                )
            raise
    
    def decode_html(self, text: str) -> str:
        """
        Decode HTML entities in text.
        
        Args:
            text (str): Text with HTML entities
            
        Returns:
            str: Decoded HTML text
            
        Example:
            >>> api = OpenCartAPI(base_url, api_key)
            >>> decoded = api.decode_html('&lt;p&gt;Hello&lt;/p&gt;')
            >>> print(decoded)
            <p>Hello</p>
        """
        return html.unescape(text)
    
    def encode_html(self, text: str, quote: bool = False) -> str:
        """
        Encode HTML special characters.
        
        Args:
            text (str): Text to encode
            quote (bool): Also encode quotes
            
        Returns:
            str: Encoded text
            
        Example:
            >>> api = OpenCartAPI(base_url, api_key)
            >>> encoded = api.encode_html('<p>Hello & World</p>')
            >>> print(encoded)
            &lt;p&gt;Hello &amp; World&lt;/p&gt;
        """
        return html.escape(text, quote=quote)
    
    # ================
    # PRODUCT METHODS
    # ================
    
    def search_products(
        self,
        name: str,
        start: int = 0,
        limit: int = 20
    ) -> Dict[str, Any]:
        """
        Search products by name with pagination support.

        Searches through product names using case-insensitive partial matching.
        Returns basic product information including ID, name, model, quantity, and status.

        Args:
            name (str): Product name to search (partial match, case-insensitive)
            start (int): Pagination start offset (default: 0)
            limit (int): Number of results per page, maximum 100 (default: 20)

        Returns:
            Dict with the following structure:
            {
                'success': bool,
                'data': [
                    {
                        'product_id': int,
                        'name': str,           # Decoded HTML entities
                        'model': str,
                        'quantity': int,
                        'status': int         # 1=enabled, 0=disabled
                    },
                    ...
                ],
                'count': int               # Number of results returned
            }

        Raises:
            ValueError: If limit exceeds 100
            requests.exceptions.HTTPError: For API errors

        Example:
            >>> results = api.search_products('iPhone', limit=5)
            >>> print(f"Found {results['count']} products")
            >>> for product in results['data']:
            ...     print(f"- {product['name']} (ID: {product['product_id']})")
        """
        if limit > 100:
            raise ValueError("Limit cannot exceed 100 items")
        
        return self._request('searchProduct', params={
            'name': name,
            'start': start,
            'limit': limit
        })
    
    def get_product(self, product_id: int) -> Dict[str, Any]:
        """
        Get complete product information including descriptions, attributes, categories, and related products.

        Retrieves comprehensive product data from OpenCart including multilingual descriptions,
        product attributes, category associations, related products, and metadata.

        Args:
            product_id (int): Product ID to retrieve

        Returns:
            Dict with the following structure:
            {
                'success': bool,
                'data': {
                    'product_id': int,
                    'date_added': str,          # ISO date string
                    'date_modified': str,       # ISO date string
                    'model': str,
                    'status': int,              # 1=enabled, 0=disabled
                    'image': str,               # Image path
                    'descriptions': {           # Multilingual descriptions
                        language_id: {
                            'name': str,         # Product name (HTML decoded)
                            'description': str,  # Full description (HTML decoded)
                            'meta_title': str,
                            'meta_description': str,
                            'meta_keyword': str,
                            'tag': str
                        },
                        ...
                    },
                    'attributes': [             # Product attributes
                        {
                            'attribute_id': int,
                            'language_id': int,
                            'key': str,          # Attribute name (HTML decoded)
                            'value': str         # Attribute value (HTML decoded)
                        },
                        ...
                    ],
                    'related_products': [       # Related product IDs and names
                        {
                            'product_id': int,
                            'name': str,
                            'model': str,
                            'status': int
                        },
                        ...
                    ],
                    'categories': [             # Associated categories
                        {
                            'category_id': int,
                            'name': str          # Category name
                        },
                        ...
                    ]
                }
            }

        Raises:
            ValueError: If product_id is invalid
            requests.exceptions.HTTPError: For API errors (404 if product not found)

        Example:
            >>> product = api.get_product(42)
            >>> print(f"Product: {product['data']['descriptions'][2]['name']}")
            >>> print(f"Attributes: {len(product['data']['attributes'])}")
            >>> for attr in product['data']['attributes'][:3]:
            ...     print(f"- {attr['key']}: {attr['value']}")
        """
        if not isinstance(product_id, int) or product_id <= 0:
            raise ValueError("Invalid product ID")
        
        return self._request('getProduct', params={'product_id': product_id})
    
    def update_product(self, product_id: int, data: Dict[str, Any], encode_html: bool = False) -> Dict[str, Any]:
        """
        Update existing product information with comprehensive field support.

        Allows updating various product fields including descriptions, attributes, categories,
        related products, and metadata. Supports multilingual content and can optionally
        encode HTML entities in the request data.

        Args:
            product_id (int): Product ID to update (must exist)
            data (Dict): Product data fields to update. Supports:
                - Basic fields: name, model, sku, quantity, price, status, etc.
                - Descriptions: description, meta_title, meta_description, meta_keyword, tag
                - Multilingual: Use language_id keys for language-specific content
                - Attributes: product_attribute array with attribute_id and text values
                - Categories: product_category array of category IDs
                - Related products: product_related array of product IDs
                - Special fields: date_available, stock_status_id, manufacturer_id, etc.
            encode_html (bool): Whether to encode HTML entities in text values before sending

        Returns:
            Dict with the following structure:
            {
                'success': bool,
                'message': str,              # Success message
                'product_id': int            # Updated product ID
            }

        Raises:
            ValueError: If product_id is invalid or data is malformed
            requests.exceptions.HTTPError: For API errors (404 if product not found)

        Example:
            >>> # Update basic product info
            >>> result = api.update_product(42, {
            ...     'price': '899.00',
            ...     'quantity': 50,
            ...     'status': 1
            ... })

            >>> # Update multilingual description
            >>> result = api.update_product(42, {
            ...     'product_description': {
            ...         1: {  # English
            ...             'name': 'iPhone 12',
            ...             'description': '<p>Latest iPhone model</p>'
            ...         },
            ...         2: {  # Persian
            ...             'name': 'آیفون ۱۲',
            ...             'description': '<p>جدیدترین مدل آیفون</p>'
            ...         }
            ...     }
            ... }, encode_html=True)

            >>> # Update product attributes
            >>> result = api.update_product(42, {
            ...     'product_attribute': [
            ...         {
            ...             'attribute_id': 5,
            ...             'product_attribute_description': {
            ...                 1: {'text': '64GB'},
            ...                 2: {'text': '۶۴ گیگابایت'}
            ...             }
            ...         }
            ...     ]
            ... })
        """
        if not isinstance(product_id, int) or product_id <= 0:
            raise ValueError("Invalid product ID")
        
        if encode_html:
            data = self._encode_html_in_dict(data)
        
        return self._request('updateProduct', 'POST', data, {
            'product_id': product_id
        })
    
    # ==================
    # ATTRIBUTE METHODS
    # ==================
    
    def search_attributes_by_key(self, key: str) -> Dict[str, Any]:
        """
        Search attributes by their name/key with multilingual support.

        Searches through attribute names (not values) using case-insensitive partial matching.
        Returns attribute definitions that can be used to assign values to products.

        Args:
            key (str): Attribute name to search (partial match, case-insensitive)

        Returns:
            Dict with the following structure:
            {
                'success': bool,
                'data': [
                    {
                        'attribute_id': int,
                        'name': str,              # Attribute name (HTML decoded)
                        'attribute_group_id': int,
                        'group_name': str,        # Associated group name
                        'sort_order': int
                    },
                    ...
                ],
                'count': int                   # Number of results
            }

        Raises:
            ValueError: If key is empty or whitespace
            requests.exceptions.HTTPError: For API errors

        Example:
            >>> results = api.search_attributes_by_key('Color')
            >>> print(f"Found {results['count']} color-related attributes")
            >>> for attr in results['data']:
            ...     print(f"- {attr['name']} (ID: {attr['attribute_id']})")
        """
        if not key.strip():
            raise ValueError("Search key cannot be empty")
        
        return self._request('searchAttributeByKey', params={'key': key})
    
    def search_attributes_by_value(self, value: str) -> Dict[str, Any]:
        """
        Search for attributes by their assigned values across all products.

        Searches through actual attribute values that have been assigned to products,
        returning attribute definitions along with usage statistics. Useful for finding
        attributes that contain specific values and understanding their usage patterns.

        Args:
            value (str): Attribute value to search for (partial match, case-insensitive)

        Returns:
            Dict with the following structure:
            {
                'success': bool,
                'data': [
                    {
                        'attribute_id': int,
                        'key': str,              # Attribute name (HTML decoded)
                        'value': str,            # The matched value (HTML decoded)
                        'attribute_group_id': int,
                        'group_name': str,       # Associated group name
                        'product_count': int     # How many products have this value
                    },
                    ...
                ],
                'count': int                   # Number of results
            }

        Raises:
            ValueError: If value is empty or whitespace
            requests.exceptions.HTTPError: For API errors

        Example:
            >>> results = api.search_attributes_by_value('Red')
            >>> print(f"Found {results['count']} attributes with 'Red' values")
            >>> for attr in results['data']:
            ...     print(f"- {attr['key']}: '{attr['value']}' ({attr['product_count']} products)")
        """
        if not value.strip():
            raise ValueError("Search value cannot be empty")
        
        return self._request('searchAttributeByValue', params={'value': value})
    
    def get_attribute(self, attribute_id: int) -> Dict[str, Any]:
        """
        Get complete attribute information including multilingual descriptions.

        Retrieves detailed attribute data from OpenCart including attribute group association,
        sort order, and multilingual name definitions.

        Args:
            attribute_id (int): Attribute ID to retrieve

        Returns:
            Dict with the following structure:
            {
                'success': bool,
                'data': {
                    'attribute_id': int,
                    'attribute_group_id': int,
                    'sort_order': int,
                    'descriptions': {           # Multilingual descriptions
                        language_id: {
                            'name': str          # Attribute name (HTML decoded)
                        },
                        ...
                    }
                }
            }

        Raises:
            ValueError: If attribute_id is invalid
            requests.exceptions.HTTPError: For API errors (404 if attribute not found)

        Example:
            >>> attr = api.get_attribute(42)
            >>> print(f"Attribute: {attr['data']['descriptions'][1]['name']}")
            >>> print(f"Group ID: {attr['data']['attribute_group_id']}")
        """
        if not isinstance(attribute_id, int) or attribute_id <= 0:
            raise ValueError("Invalid attribute ID")
        
        return self._request('getAttribute', params={'attribute_id': attribute_id})
    
    def add_attribute(self, data: Dict[str, Any], encode_html: bool = False) -> Dict[str, Any]:
        """
        Add new attribute.
        
        Args:
            data (Dict): Attribute data structure
            encode_html (bool): Whether to encode HTML in descriptions
            
        Returns:
            Dict containing created attribute ID
            
        Example:
            >>> new_attr = api.add_attribute({
            ...     'attribute_group_id': 6,
            ...     'sort_order': 10,
            ...     'attribute_description': {
            ...         '1': {'name': 'Battery'},
            ...         '2': {'name': 'باتری'}
            ...     }
            ... }, encode_html=True)
        """
        if not data or not isinstance(data, dict):
            raise ValueError("Invalid attribute data")
        
        if encode_html:
            data = self._encode_html_in_dict(data)
        
        return self._request('addAttribute', 'POST', data)
    
    # ========================
    # ATTRIBUTE GROUP METHODS
    # ========================
    
    def search_attribute_groups(self, name: Optional[str] = None) -> Dict[str, Any]:
        """
        Search attribute groups by name.
        
        Args:
            name (Optional[str]): Group name filter (case-insensitive)
            
        Returns:
            Dict containing matched attribute groups
            
        Example:
            >>> groups = api.search_attribute_groups('Specification')
        """
        params = {}
        if name:
            params['name'] = name
        
        return self._request('searchAttributeGroup', params=params)
    
    def add_attribute_group(self, data: Dict[str, Any], encode_html: bool = False) -> Dict[str, Any]:
        """
        Add new attribute group.
        
        Args:
            data (Dict): Attribute group data structure
            encode_html (bool): Whether to encode HTML in descriptions
            
        Returns:
            Dict containing created group ID
            
        Example:
            >>> new_group = api.add_attribute_group({
            ...     'sort_order': 1,
            ...     'attribute_group_description': {
            ...         '2': {'name': 'Technical Specifications'}
            ...     }
            ... }, encode_html=True)
        """
        if not data or not isinstance(data, dict):
            raise ValueError("Invalid attribute group data")
        
        if encode_html:
            data = self._encode_html_in_dict(data)
        
        return self._request('addAttributeGroup', 'POST', data)
    
    # ===================
    # CATEGORY METHODS
    # ===================
    
    def get_all_categories(
        self, 
        start: int = 0, 
        limit: int = 20
    ) -> Dict[str, Any]:
        """
        Get all categories with pagination.
        
        Args:
            start (int): Pagination start offset
            limit (int): Number of results per page (max 100)
            
        Returns:
            Dict containing category list
            
        Example:
            >>> categories = api.get_all_categories(limit=10)
        """
        if limit > 100:
            raise ValueError("Limit cannot exceed 100 items")
        
        return self._request('getAllCategories', params={
            'start': start,
            'limit': limit
        })
    
    def search_categories(self, name: str) -> Dict[str, Any]:
        """
        Search categories by name.
        
        Args:
            name (str): Category name to search
            
        Returns:
            Dict containing matched categories
            
        Example:
            >>> cats = api.search_categories('Electronics')
        """
        if not name.strip():
            raise ValueError("Search name cannot be empty")
        
        return self._request('searchCategories', params={'name': name})
    
    # =================
    # SYSTEM METHODS
    # =================
    
    def get_debug_info(self) -> Dict[str, Any]:
        """
        Get comprehensive system debug information and API configuration details.

        Retrieves detailed information about the OpenCart installation, API configuration,
        detected file paths, and system settings. Useful for troubleshooting API connectivity
        and understanding the server environment.

        Returns:
            Dict with the following structure:
            {
                'success': bool,
                'opencart_version': str,        # Full OpenCart version string
                'detected_version': int,        # Parsed major version (2 or 3)
                'token_name': str,              # Token parameter name ('user_token' for v3, 'token' for v2)
                'seo_table': str,               # SEO URL table name ('seo_url' for v3, 'url_alias' for v2)
                'detected_model_path': str,     # Path to detected admin model files
                'all_searched_paths': [         # All paths searched for model files
                    {
                        'path': str,
                        'exists': bool,         # Whether the path exists
                        'is_detected': bool     # Whether this path was selected
                    },
                    ...
                ],
                'dir_application': str,         # Application directory path
                'dir_system': str,              # System directory path (if defined)
                'dir_storage': str              # Storage directory path (if defined)
            }

        Raises:
            requests.exceptions.HTTPError: For API errors

        Example:
            >>> debug = api.get_debug_info()
            >>> print(f"OpenCart Version: {debug['opencart_version']}")
            >>> print(f"API Token Type: {debug['token_name']}")
            >>> print(f"Model Path: {debug['detected_model_path']}")
            >>> print(f"Searched Paths: {len(debug['all_searched_paths'])}")
        """
        return self._request('debugInfo')


# Usage Example with Full Error Handling
if __name__ == '__main__':
    # Configuration
    BASE_URL = 'http://localhost/opencart2maxshop'
    API_KEY = 'sds!dwd3dsSFSd111!'
    
    # Initialize client
    api = OpenCartAPI(
        base_url=BASE_URL,
        api_key=API_KEY,
        auto_decode_html=True,
        timeout=15
    )
    
    try:
        # 1. Search products
        print("🔍 Searching products...")
        products = api.search_products('موبایل', limit=3)
        print(f"✅ Found {products['count']} products")
        if products['count'] > 0:
            print(json.dumps(products['data'], indent=2, ensure_ascii=False))
        
        # 2. Get product details
        print("\n📦 Getting product details...")
        product = api.get_product(69)
        print(f"{product['data']}")
        
        # 3. Search attributes
        #print("\n🏷️ Searching attributes by key...")
        #attrs = api.search_attributes_by_key('تست')
        #print(f"✅ Found {attrs}")

        
        # 6. Update product example (commented out for safety)
        # print("\n✏️ Updating product...")
        # update_result = api.update_product(69, {
        #     'price': '1499.00',
        #     'quantity': '50',
        #     'description': '<p>Updated description with <b>HTML</b></p>'
        # }, encode_html=True)
        # print(f"✅ Update successful: {update_result['message']}")
        
    except requests.exceptions.HTTPError as e:
        print(f"❌ HTTP Error: {e}")
        if hasattr(e, 'response') and e.response is not None:
            try:
                error_data = e.response.json()
                print(f"API Error Details: {error_data.get('error', 'No details')}")
            except:
                print(f"Response Text: {e.response.text}")
                
    except ValueError as e:
        print(f"❌ Value Error: {e}")
        
    except Exception as e:
        print(f"❌ Unexpected Error: {type(e).__name__} - {str(e)}")
    
    finally:
        # Close session
        api.session.close()
