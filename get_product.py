import requests
import json
import html
from typing import Dict, List, Optional, Union, Any, Tuple


class OpenCartAPI:
    """
    OpenCart API Client with automatic HTML entity handling.
    
    Args:
        base_url (str): OpenCart base URL
        api_key (str): API authentication key
        auto_decode_html (bool): Automatically decode HTML entities in responses
        timeout (int): Request timeout in seconds (default: 30)
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
        
        # Prepare parameters
        full_params = {'route': f'api/product_api/{endpoint}'}
        if params:
            full_params.update(params)
        
        print(full_params)
        try:
            if method == 'GET':
                response = self.session.get(
                    url, 
                    params=full_params,
                    timeout=self.timeout
                )
            elif method == 'POST':
                response = self.session.post(
                    url, 
                    params=full_params,
                    json=data,
                    timeout=self.timeout
                )
            elif method == 'DELETE':
                response = self.session.delete(
                    url, 
                    params=full_params,
                    timeout=self.timeout
                )
            else:
                raise ValueError(f"Unsupported HTTP method: {method}")
            
            # Handle non-200 responses
            response.raise_for_status()
            
            # Parse JSON response
            try:
                result = response.json()
            except json.JSONDecodeError:
                raise ValueError(f"Invalid JSON response: {response.text}")
            
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
        Search products by name.
        
        Args:
            name (str): Product name to search
            start (int): Pagination start offset
            limit (int): Number of results per page (max 100)
            
        Returns:
            Dict containing search results with decoded HTML
            
        Example:
            >>> results = api.search_products('iPhone', limit=5)
            >>> print(f"Found {results['count']} products")
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
        Get product details with decoded HTML descriptions.
        
        Args:
            product_id (int): Product ID to retrieve
            
        Returns:
            Dict containing product details
            
        Example:
            >>> product = api.get_product(42)
            >>> print(product['data']['descriptions'][2]['name'])
        """
        if not isinstance(product_id, int) or product_id <= 0:
            raise ValueError("Invalid product ID")
        
        return self._request('getProduct', params={'product_id': product_id})
    
    def update_product(
        self, 
        product_id: int, 
        data: Dict[str, Any],
        encode_html: bool = False
    ) -> Dict[str, Any]:
        """
        Update product with optional HTML encoding.
        
        Args:
            product_id (int): Product ID to update
            data (Dict): Product data fields to update
            encode_html (bool): Whether to encode HTML in data values
            
        Returns:
            Dict containing update result
            
        Example:
            >>> result = api.update_product(42, {
            ...     'description': '<p>New description</p>',
            ...     'price': '899.00'
            ... }, encode_html=True)
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
        Search attributes by name/key.
        
        Args:
            key (str): Attribute name to search for
            
        Returns:
            Dict containing matched attributes
            
        Example:
            >>> attrs = api.search_attributes_by_key('Color')
        """
        if not key.strip():
            raise ValueError("Search key cannot be empty")
        
        return self._request('searchAttributeByKey', params={'key': key})
    
    def search_attributes_by_value(self, value: str) -> Dict[str, Any]:
        """
        Search attributes by value.
        
        Args:
            value (str): Attribute value to search for
            
        Returns:
            Dict containing matched attributes
            
        Example:
            >>> attrs = api.search_attributes_by_value('Red')
        """
        if not value.strip():
            raise ValueError("Search value cannot be empty")
        
        return self._request('searchAttributeByValue', params={'value': value})
    
    def get_attribute(self, attribute_id: int) -> Dict[str, Any]:
        """
        Get attribute details.
        
        Args:
            attribute_id (int): Attribute ID to retrieve
            
        Returns:
            Dict containing attribute details
            
        Example:
            >>> attr = api.get_attribute(42)
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
        Get system debug information.
        
        Returns:
            Dict containing server configuration details
            
        Example:
            >>> debug = api.get_debug_info()
            >>> print(f"OpenCart Version: {debug['data']['opencart_version']}")
        """
        return self._request('debugInfo')


# Usage Example with Full Error Handling
if __name__ == '__main__':
    # Configuration
    BASE_URL = 'http://localhost/'
    API_KEY = 'key'
    
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
            print(json.dumps(products['data'][0], indent=2, ensure_ascii=False))
        
        # 2. Get product details
        print("\n📦 Getting product details...")
        product = api.get_product(69)
        print(f"✅ Retrieved product: {product['data']}")
        
        # 3. Search attributes
        print("\n🏷️ Searching attributes by key...")
        attrs = api.search_attributes_by_key('تست')
        print(f"✅ Found {attrs}")
        
        # 4. Get debug info
        print("\n⚙️ Getting debug info...")
        debug = api.get_debug_info()
        print(f"{debug}")
        
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
        print("\n🔒 Session closed")
