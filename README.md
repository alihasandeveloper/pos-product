# POS Product Sync API Documentation

This document describes the REST API provided by the **Pos Product Sync** WordPress plugin for syncing products between your POS system and WooCommerce.

## Base URL
The API endpoint is registered under the WordPress REST API infrastructure.

```
POST /wp-json/pos/v1/product
```

## Authentication
Every request must include an `Authorization` header with a specific Bearer token.

**Header Format:**
```
Authorization: Bearer {token}
```

**Required Token:**
```
eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJ0ZXN0LXVzZXIiLCJhdWQiOiJhcGkuZXhhbXBsZS5jb20iLCJpYXQiOjE2OTg1NjAwMDAsImV4cCI6MTY5ODU2MzYwMH0.4JcF5yO3z5uBvFhOQwI8JrR6qJ8tP9x7yQnPjG4kHhA
```

---

## Endpoint Details

All operations (Create, Update, Delete) use the exact same endpoint. The action to perform is determined by the `eventType` field in the JSON request body.

### 1. Create a Product

Creates a new WooCommerce product and syncs POS-specific metadata.

**Request Body (JSON):**

| Field | Type | Required | Description |
|---|---|---|---|
| `eventType` | String | **Yes** | Must be exactly `"create"` |
| `Id` | String | **Yes** | The unique Product ID from the POS system |
| `Name` | String | No | Name of the product |
| `Price` | Number | No | The regular selling price |
| `OldPrice` | Number | No | Optional old regular price (used if `Price` is empty) |
| `Code` | String | No | The product SKU |
| `Description` | String | No | Product description |
| `CurrentStock` | Number | No | Total stock quantity. Used if `WarehouseList` is empty. |
| `WarehouseList` | Array | No | List of locations. Ex: `[{"CurrentStock": 10}, {"CurrentStock": 5}]`. The system will sum the `CurrentStock` across all warehouses. |
| `CategoryName` | String | No | Name of the category. Will be created if it doesn't exist. |
| `BrandName` | String | No | Stored as a product tag. Will be created if it doesn't exist. |
| `Type` | String | No | POS Product Type |
| `ProductBarcode` | String | No | Barcode string |
| `UnitName` | String | No | Storage/Measurement unit name (e.g., 'kg', 'pcs') |
| `CostPrice` | Number | No | Cost price from the POS |
| `ImagePath` | String | No | Public URL of the product image. Will be downloaded and set as the featured image. |

**Example Request:**
```json
{
  "eventType": "create",
  "Id": "POS-1001",
  "Name": "Sample T-Shirt",
  "Price": 25.99,
  "Code": "TSHIRT-001",
  "Description": "A cool cotton t-shirt",
  "CurrentStock": 50,
  "CategoryName": "Clothing",
  "BrandName": "AwesomeBrand",
  "ProductBarcode": "123456789012",
  "CostPrice": 10.00,
  "ImagePath": "https://example.com/images/tshirt.jpg"
}
```

**Success Response (201 Created):**
```json
{
  "success": true,
  "message": "Product created successfully",
  "data": {
    "product_id": 123
  }
}
```

**Error Responses:**
- `422 Unprocessable Entity`: "Product Id required"
- `409 Conflict`: "Product already exists"
- `500 Internal Server Error`: "Failed to create product"

---

### 2. Update a Product

Updates an existing WooCommerce product that is synced with the provided POS ID. 

**Request Body (JSON):**

| Field | Type | Required | Description |
|---|---|---|---|
| `eventType` | String | **Yes** | Must be exactly `"update"` |
| `Id` | String | **Yes** | The unique Product ID from the POS system |
| `Name` | String | No | New name of the product |
| `Price` | Number | No | New selling price |
| `OldPrice` | Number | No | New old regular price |
| `Code` | String | No | New product SKU |
| `Description` | String | No | New product description |
| `CurrentStock` / `WarehouseList` | Number / Array | No | Updates total stock quantity |
| `CategoryName` | String | No | New category for the product |
| `BrandName` | String | No | New product tag (brand) |
| `ProductBarcode` | String | No | New barcode string |
| `UnitName` | String | No | New unit name |
| `CostPrice` | Number | No | New cost price |
| `ImagePath` | String | No | **Important:** A new URL will download and replace the existing featured image. |

*Note: Omitted fields will generally retain their existing values in WooCommerce.*

**Example Request:**
```json
{
  "eventType": "update",
  "Id": "POS-1001",
  "Price": 19.99,
  "CurrentStock": 35
}
```

**Success Response (200 OK):**
```json
{
  "success": true,
  "message": "Product updated successfully",
  "data": {
    "product_id": 123
  }
}
```

**Error Responses:**
- `422 Unprocessable Entity`: "Product Id required"
- `404 Not Found`: "Product not found"
- `500 Internal Server Error`: "Failed to load product" / "Failed to update product"

---

### 3. Delete a Product

Permanently deletes a WooCommerce product connected to the specific POS ID. It bypasses the trash bin.

**Request Body (JSON):**

| Field | Type | Required | Description |
|---|---|---|---|
| `eventType` | String | **Yes** | Must be exactly `"delete"` |
| `Id` | String | **Yes** | The unique Product ID from the POS system to delete. |

**Example Request:**
```json
{
  "eventType": "delete",
  "Id": "POS-1001"
}
```

**Success Response (200 OK):**
```json
{
  "success": true,
  "message": "Product deleted successfully",
  "data": []
}
```

**Error Responses:**
- `422 Unprocessable Entity`: "Product Id required"
- `404 Not Found`: "Product not found"
- `500 Internal Server Error`: "Failed to load product"
