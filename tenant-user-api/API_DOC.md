# API Documentation

## Overview
Base URL: `http://127.0.0.1:9998`
Response Format: JSON

## Authentication
This API uses JWT (JSON Web Token) for authentication.
Token Validity: 7 days.
Header: `Authorization: Bearer <token>`

## Error Codes
| Code | Message | Description |
| :--- | :--- | :--- |
| 200 | Success | Request processed successfully |
| 400 | Bad Request | Invalid input parameters |
| 401 | Unauthorized | Invalid token or login failed |
| 403 | Forbidden | Account disabled or cross-tenant access denied |
| 423 | Locked | Account locked due to too many failed attempts |
| 500 | Internal Server Error | Server logic error |

---

## Endpoints

### 1. Account Login
**Endpoint:** `POST /api/auth/login`

**Description:** Authenticate user via username and password.

**Request Body:**
```json
{
    "username": "your_username",
    "password": "your_password"
}
```

**Response (Success):**
```json
{
    "code": 200,
    "msg": "登录成功",
    "data": {
        "token": "Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
        "expires_in": 604800,
        "user": {
            "id": 1,
            "username": "admin",
            "name": "Admin User"
        }
    }
}
```

**Response (Failed - Wrong Password):**
```json
{
    "code": 401,
    "msg": "用户名或密码错误",
    "data": null
}
```

**Response (Failed - Locked):**
```json
{
    "code": 423,
    "msg": "密码错误次数过多，账号已锁定30分钟",
    "data": null
}
```

---

### 2. Test Protected Route
**Endpoint:** `GET /api/v1/test`

**Headers:**
`Authorization: Bearer <your_token>`

**Response (Success):**
```json
{
    "code": 200,
    "msg": "You are authenticated",
    "tenant_id": 1
}
```

**Response (Unauthorized):**
```json
{
    "code": 401,
    "msg": "Invalid Token Payload"
}
```
