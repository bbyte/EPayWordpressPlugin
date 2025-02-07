# ePay.bg ONE TOUCH API Documentation
*Version: 2025-02-08*

> **Note**: This is a snapshot of the API documentation. For the latest version, please visit the [official ePay.bg API documentation](https://www.epay.bg/v3main/front?p=api#api_start).

## Table of Contents
- [Overview](#overview)
- [Communication](#communication)
- [Authorization and Token Management](#authorization-and-token-management)
- [User Information and Payment Instruments](#user-information-and-payment-instruments)
- [Sending Money](#sending-money)
- [Unregistered User Payments](#unregistered-user-payments)

## Overview

ePay.bg ONE TOUCH is an interface for communicating with ePay.bg servers. To use it, you need a registered application that ePay.bg users can authorize to perform specific actions. Each application has:

- A secret key (`SECRET`)
- A reply address (`REPLY_ADDRESS`) - your callback URL for web applications or URL Scheme for mobile apps
- A unique identifier (`APPID`) sent with every request

## Communication

### API Endpoints
**Test Environment:**
- Main API: `https://demo.epay.bg/xdev/api`
- Web API: `https://demo.epay.bg/xdev/mobile`

### Request Validation
- Parameter: `APPCHECK`
- Algorithm: `hmac_sha1_hex(request_data, SECRET)`
- Format: Parameters concatenated with NEWLINE, sorted by name
- Token requests: Include client number + NEWLINE

### Response Format
```json
// Success
{
    \"status\": \"OK\",
    // Additional data based on request
}

// Error
{
    \"status\": \"ERR\",
    \"err\": \"ERROR_CODE\",
    \"errm\": \"User-friendly error message\"
}
```

## Authorization and Token Management

### Required Parameters
- `APPID`: Application identifier
- `DEVICEID`: Unique device identifier
- `TOKEN`: User authorization token

### Getting a Token

1. **Authorization Request**
   ```
   GET API_BASE_WEB/api/start
   Parameters:
   - DEVICEID (required)
   - APPID (required)
   - KEY (required)
   - DEVICE_NAME (recommended)
   - BRAND (recommended)
   - OS (recommended)
   - MODEL (recommended)
   - OS_VERSION (recommended)
   - PHONE (recommended)
   - UTYPE (1=registered users, 2=unregistered users)
   ```

2. **Get Token Code**
   ```
   GET API_BASE/api/code/get
   Parameters:
   - DEVICEID (required)
   - APPID (required)
   - KEY (required)
   ```

3. **Get Token**
   ```
   GET API_BASE/api/token/get
   Parameters:
   - DEVICEID (required)
   - APPID (required)
   - CODE (required)
   ```

### Invalidating a Token
```
GET API_BASE/api/token/invalidate
Parameters:
- APPID (required)
- DEVICEID (required)
- TOKEN (required)
```

## Payment Processing

### Initialize Payment
```
POST API_BASE/payment/init
Parameters:
- TYPE=\"send\" (required)
- EXP=Unix Time (optional, payment ID expiration)
```

### Payment Visibility Options
```json
{
    \"payment\": {
        \"SHOW.NAME\": Integer,
        \"SHOW.KIN\": Integer,
        \"SHOW.GSM\": Integer,
        \"SHOW.EMAIL\": Integer
    }
}
```

### Additional Parameters
- `SAVECARD`: Set to 1 to save card for future payments
- `APPCHECK`: Request validation checksum

## Important Notes
- All requests must include `APPID`, `DEVICEID`, and `TOKEN` (after authorization)
- Token codes expire after 2 minutes
- For mobile apps, use Vendor ID (iOS) or ANDROID_ID (Android) as `DEVICEID`
- Contact ePay.bg's Commercial Department to register your application
