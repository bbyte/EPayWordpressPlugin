# ePay OneTouch API Documentation

## Before You Begin

### Description
ePay.bg OneTouch is an interface for communication with ePay.bg servers. Each registered application receives:
- A secret key (SECRET)
- A unique identifier (APPID) that you send with every request to our servers

The application enables:
- Processing payments from customer bank cards to merchant accounts in ePay.bg
- Balance inquiries for payment instruments (bank cards, ePay.bg accounts)

Your customers can make payments through the ePay system in two ways:
1. Without an ePay.bg user profile (No Registration API)
2. Using their ePay.bg user profile

ePay.bg users have access to:
- ePay account (Microaccount)
- Option to add and use bank credit/debit accounts and cards
- Payments through [epay.bg](https://epay.bg/) profile or ePay.bg mobile app

### Test Environment
Test endpoints:
- API: `https://demo.epay.bg/xdev/api`
- Mobile: `https://demo.epay.bg/xdev/mobile`

Response formats:
- Success:
  ```json
  {
      "status": "OK",
      // Additional data based on the request
  }
  ```
- Error:
  ```json
  {
      "status": "ERR",
      "err": "ERROR_CODE",
      "errm": "User-friendly error message"
  }
  ```

### Security
- Card tokenization is handled through a hosted form on ePay.bg servers
- All sensitive card data is processed securely on ePay.bg infrastructure

**Sources:**
- [ePay OneTouch API Documentation](https://kb.epay.bg/bg/onetouch/onetouch/)
- [ePay OneTouch No Registration API Documentation](https://kb.epay.bg/bg/onetouch/onetouch_no_reg/)
- [Before You Begin Guide](https://kb.epay.bg/bg/onetouch/ot_pre/)

## Overview
The ePay OneTouch API provides a seamless way to integrate payment functionality into your applications. It supports card payments and bank account transfers through a secure token-based authentication system.

## Prerequisites
1. Contact ePay's Commercial Department
2. Obtain your `APPID`
3. Modern browser with Promise and Web Crypto API support

## Base URLs
- Demo: `https://demo.epay.bg/xdev`
- Production: Contact ePay for production URL

## Authentication Flow

### 1. Start Authentication
```
GET /api/start
```
Redirects the user to ePay.bg's hosted page for authorization and card data entry.

**Parameters:**
- `APPID` (required): Application identifier
- `DEVICEID` (required): Unique device identifier
  - iOS: Use Vendor ID
  - Android: Use ANDROID_ID
  - Web: Generated using Web Crypto API (recommended)
    - Primary: `window.crypto.randomUUID()`
    - Fallback: Custom UUID v4 implementation using `crypto.getRandomValues()`
- `KEY` (required): Unique key for request verification
- `UTYPE` (optional): User type restriction
  - `1`: Only ePay.bg registered users
  - `2`: Only payment card users
- Device Info (optional but recommended):
  - `DEVICE_NAME`: Device name
  - `BRAND`: Device brand
  - `OS`: Operating system
  - `MODEL`: Device model
  - `OS_VERSION`: OS version
  - `PHONE`: User's phone number

**Important Notes:**
- The combination of `DEVICEID` & `KEY` must be unique
- Including device information is recommended for better permission management
- Device IDs are securely generated and stored using the following priority:
  1. `localStorage` (persistent storage)
  2. `sessionStorage` (session-only storage)
  3. Error if no storage is available
- All device ID operations are Promise-based for better error handling

### 2. Get Authorization Code
```
GET /api/code/get
```

**Parameters:**
- `APPID` (required): Application identifier
- `DEVICEID` (required): Device identifier
- `KEY` (required): Unique key from step 1

**Response:**
```json
{
    "status": "OK",
    "code": "token_code"
}
```

### 3. Get Token
```
GET /api/token/get
```

**Parameters:**
- `APPID` (required): Application identifier
- `DEVICEID` (required): Device identifier
- `CODE` (required): Code received from previous step

**Success Response:**
```json
{
    "status": "OK",
    "TOKEN": "token_string",
    "EXPIRES": 1720188520,
    "KIN": "client uniq number",
    "USERNAME": "client username",
    "REALNAME": "client real name"
}
```

### 4. Token Management

#### Invalidate Token
```
GET /api/token/invalidate
```

**Parameters:**
- `APPID` (required): Application identifier
- `DEVICEID` (required): Device identifier
- `TOKEN` (required): Token to invalidate

## User Information

### Get User Info
```
GET /user/info
```

**Parameters:**
- `APPID` (required): Application ID
- `DEVICEID` (required): Device identifier
- `TOKEN` (required): User token
- `PINS` (optional): Set to 1 to include payment instruments info

**Success Response:**
```json
{
    "status": "OK",
    "userinfo": {
        "GSM": "",
        "PIC": "User's photo URL",
        "REAL_NAME": "User's registered name",
        "ID": "User identifier",
        "KIN": "Client Identification Number",
        "EMAIL": "user@email.com"
    },
    "payment_instruments": [
        {
            "ID": "microaccount_id",
            "VERIFIED": 1,
            "BALANCE": "21015",
            "PIC": "",
            "TYPE": 2,
            "NAME": "Microaccount",
            "EXPIRES": ""
        },
        {
            "ID": "card_id",
            "VERIFIED": 1,
            "BALANCE": "",
            "PIC": "",
            "TYPE": 1,
            "NAME": "User's card name",
            "EXPIRES": "03/2017",
            "CARD_TYPE": "5",
            "CARD_TYPE_COUNTRY": "",
            "CARD_TYPE_DESCR": "MasterCard"
        }
    ]
}
```

### Get Payment Instruments
```
GET /user/info/pins
```

**Parameters:**
- `APPID` (required): Application ID
- `DEVICEID` (required): Device identifier
- `TOKEN` (required): User token

**Success Response:**
```json
{
    "status": "OK",
    "payment_instruments": [
        {
            "ID": "microaccount_id",
            "VERIFIED": 1,
            "BALANCE": "9808668",
            "PIC": "",
            "TYPE": 2,
            "NAME": "Microaccount",
            "EXPIRES": "",
            "CARD_TYPE": "",
            "CARD_TYPE_COUNTRY": "",
            "CARD_TYPE_DESCR": ""
        },
        {
            "ID": "card_id",
            "VERIFIED": 1,
            "BALANCE": "",
            "PIC": "",
            "TYPE": 1,
            "NAME": "Bank Card",
            "EXPIRES": "12/2025",
            "CARD_TYPE": "5",
            "CARD_TYPE_COUNTRY": "",
            "CARD_TYPE_DESCR": "MasterCard"
        }
    ]
}
```

### Check Balance
```
GET /user/info/pins/balance
```

**Parameters:**
- `APPID` (required): Application ID
- `DEVICEID` (required): Device identifier
- `TOKEN` (required): User token
- `PINS` (required): Microaccount ID to check balance for

**Success Response:**
```json
{
    "status": "OK",
    "payment_instruments": [
        {
            "STATUS": "OK",
            "ID": "microaccount_id",
            "BALANCE": "9808668",
            "TYPE": 2,
            "NAME": "Microaccount",
            "EXPIRES": ""
        }
    ]
}
```

**Response Fields:**
- `VERIFIED`: 1 if the payment instrument is verified
- `BALANCE`: Amount in stotinki (only for microaccounts)
- `TYPE`: Payment instrument type (1: Bank Card, 2: Microaccount)
- `EXPIRES`: Card expiry date in MM/YYYY format (only for cards)
- `CARD_TYPE`: Card type identifier
- `CARD_TYPE_DESCR`: Card type description (e.g., "MasterCard")

## Security

### Checksum (Optional)
To validate requests, you can use the `APPCHECK` parameter. The checksum is generated as follows:

```
hmac_sha1_hex(request_data, SECRET)
```

where `request_data` is concatenated with newline (`\n`) containing parameter names and values, sorted in ascending order by parameter name. For requests requiring a token, the user's KIN must be appended with a newline.

## User Information

### Get User Info
```
GET /user/info
```

**Parameters:**
- `APPID`: Application ID
- `DEVICEID`: Device identifier
- `TOKEN`: User token
- `PINS`: Set to 1 to include payment instruments

**Success Response:**
```json
{
    "status": "OK",
    "userinfo": {
        "GSM": "",
        "PIC": "user_photo_url",
        "REAL_NAME": "User's registered name",
        "ID": "user_identifier",
        "KIN": "Client Identification Number",
        "EMAIL": "user@email.com"
    },
    "payment_instruments": [
        {
            "ID": "payment_instrument_id",
            "VERIFIED": 1,
            "BALANCE": "21015",
            "PIC": "",
            "TYPE": 2,
            "NAME": "Microaccount",
            "EXPIRES": ""
        },
        {
            "ID": "card_id",
            "VERIFIED": 1,
            "BALANCE": "",
            "PIC": "",
            "TYPE": 1,
            "NAME": "User's card name",
            "EXPIRES": "03/2017"
        }
    ]
}
```

### Get Payment Instruments
```
GET /user/info/pins
```

**Parameters:**
- `APPID`: Application ID
- `DEVICEID`: Device identifier
- `TOKEN`: User token

**Success Response:**
```json
{
    "status": "OK",
    "payment_instruments": [
        {
            "ID": "microaccount_id",
            "VERIFIED": 1,
            "BALANCE": "9808668",
            "PIC": "",
            "TYPE": 2,
            "NAME": "Microaccount",
            "EXPIRES": "",
            "CARD_TYPE": "",
            "CARD_TYPE_COUNTRY": "",
            "CARD_TYPE_DESCR": ""
        },
        {
            "ID": "card_id",
            "VERIFIED": 1,
            "BALANCE": "",
            "PIC": "",
            "TYPE": 1,
            "NAME": "Bank Card",
            "EXPIRES": "12/2025",
            "CARD_TYPE": "5",
            "CARD_TYPE_COUNTRY": "",
            "CARD_TYPE_DESCR": "MasterCard"
        }
    ]
}
```

### Check Balance
```
GET /user/info/pins/balance
```

**Parameters:**
- `APPID`: Application ID
- `DEVICEID`: Device identifier
- `TOKEN`: User token
- `PINS`: Microaccount ID

**Success Response:**
```json
{
    "status": "OK",
    "payment_instruments": [
        {
            "STATUS": "OK",
            "ID": "microaccount_id",
            "BALANCE": "9808668",
            "TYPE": 2,
            "NAME": "Microaccount",
            "EXPIRES": ""
        }
    ]
}
```

## Authentication Flow

### 1. Generate User Token
First, you need to generate a user token which will be used for subsequent API calls.

#### Request Token Code
```
GET https://demo.epay.bg/xdev/api/api/code/get
```

**Parameters:**
- `APPID`: Your application ID
- `DEVICEID`: Unique device identifier
- `KEY`: Unique key for the request

**Response:**
```json
{
    "status": "OK",
    "code": "token_code"
}
```

#### Get Token
```
GET https://demo.epay.bg/xdev/api/api/token/get
```

**Parameters:**
- `APPID`: Your application ID
- `DEVICEID`: Device identifier
- `CODE`: Token code received from previous request

**Success Response:**
```json
{
    "status": "OK",
    "TOKEN": "token_string",
    "EXPIRES": 1720188520,
    "KIN": "client uniq number",
    "USERNAME": "client username",
    "REALNAME": "client real name"
}
```

### 2. Token Management

#### Invalidate Token
```
GET https://demo.epay.bg/xdev/api/api/token/invalidate
```

**Parameters:**
- `APPID`: Your application ID
- `DEVICEID`: Device identifier
- `TOKEN`: Token to invalidate

## Payment Flow

The payment process consists of four steps:

1. Initialize Payment
2. Check Payment Details
3. Send User Payment
4. Check Payment Status

### Payment Instrument Types
- Type 1: Bank Cards
- Type 2: Microaccount

### Payment States
- State 2: Processing
- State 3: Pending
- State 4: Payment Complete

### Display Options
Use the `SHOW` parameter to control what information is visible to the payment recipient:
- `KIN`: Client number in ePay.bg (default)
- `GSM`: Recipient's mobile number registered in ePay.bg
- `EMAIL`: Recipient's email registered in ePay.bg
- `NAME`: Recipient's name (for ePay.bg clients)

Multiple options can be combined with commas: `KIN,GSM,EMAIL,NAME`

### 1. Initialize Payment
```
POST /payment/init
```

**Parameters:**
- `APPID` (required): Application ID
- `DEVICEID` (required): Device identifier
- `TOKEN` (required): User token
- `TYPE` (required): Must be "send"

**Success Response:**
```json
{   
    "payment": {
        "ID": "UsYGw8-pTlZU4DJOAYT91_v-l30SMjADFA6AYPWYbJI"
    },
    "status": "OK"
}
```

### 2. Check Payment Details
```
POST /payment/check
```

**Parameters:**
- `APPID` (required): Application ID
- `DEVICEID` (required): Device identifier
- `TOKEN` (required): User token
- `TYPE` (required): Must be "send"
- `ID` (required): Payment ID from init step
- `AMOUNT` (required): Payment amount in stotinki
- `RCPT` (required): Recipient's KIN
- `RCPT_TYPE` (required): Must be "KIN"
- `DESCRIPTION` (required): Payment description
- `REASON` (required): Payment reason
- `PINS` (required): Payment instrument ID to use
- `SHOW` (required): Display options (KIN, NAME, GSM, EMAIL)

**Success Response:**
```json
{
    "payment": {
        "ID": "UsYGw8-pTlZU4DJOAYT91_v-l30SMjADFA6AYPWYbJI",
        "PAYMENT_INSTRUMENTS": [
            {   
                "ID": "UsYGw8-pTlZU4DJOAYT91xPUP8YqpocJScram3nKxVs",
                "NAME": "Payment Method Name",
                "TAX": 1,
                "TOTAL": 35,
                "STATUS": "OK"
            }
        ],
        "AMOUNT": 34,
        "DATA": {
            "RCPT_TYPE": "KIN",
            "RCPT": "3894711478"
        }
    },
    "status": "OK"
}
```

### 3. Send User Payment
```
POST /payment/send/user
```

**Parameters:**
- All parameters from the check payment request (all required)
- Additional display options through `SHOW` parameter

**Success Response:**
```json
{
    "payment": {
        "ID": "UsYGw8-pTlZU4DJOAYT91_v-l30SMjADFA6AYPWYbJI",
        "AMOUNT": 34,
        "TAX": 1,
        "TOTAL": 35,
        "RCPT": "3894711478",
        "RCPT_TYPE": "KIN",
        "DESCRIPTION": "",
        "REASON": "",
        "STATE": 2,
        "STATE.TEXT": "Processing",
        "PINS": "UsYGw8-pTlZU4DJOAYT918PwRo4yHpe9i2EP4MXhQYVswVNAZdgJUljcrW22_Z9D9frQUAwOWHst\nN924CNqh3A",
        "SHOW": "6051143866",
        "SHOW.KIN": 1,
        "SHOW.NAME": 0,
        "SHOW.GSM": 0,
        "SHOW.EMAIL": 0,
        "NO": ""
    },
    "status": "OK"
}
```

### 4. Check Payment Status
```
POST /payment/send/status
```

**Parameters:**
- All parameters from the send payment request (all required)

**Success Response:**
```json
{
    "status": "OK",
    "payment": {
        "ID": "UsYGw8-pTlZU4DJOAYT91_v-l30SMjADFA6AYPWYbJI",
        "AMOUNT": 34,
        "TAX": 1,
        "TOTAL": 35,
        "RCPT": "3894711478",
        "RCPT_TYPE": "KIN",
        "DESCRIPTION": "",
        "REASON": "",
        "STATE": 4,
        "STATE.TEXT": "Payment Complete",
        "PINS": "UsYGw8-pTlZU4DJOAYT918PwRo4yHpe9i2EP4MXhQYVswVNAZdgJUljcrW22_Z9D9frQUAwOWHst\nN924CNqh3A",
        "SHOW": "6051143866",
        "SHOW.KIN": "6051143866",
        "SHOW.NAME": "",
        "SHOW.GSM": "",
        "SHOW.EMAIL": "",
        "NO": "2000000000032229"
    },
    "savecard": 1
}
```

**Response Fields:**
- `AMOUNT`: Amount in stotinki
- `TAX`: Transaction fee
- `TOTAL`: Total amount including fee
- `STATE`: Payment state (2: Processing, 3: Pending, 4: Complete)
- `NO`: Transaction number (when payment is complete)
- `savecard`: Indicates if the card was saved for future use

## Payment States

Payments can have the following states:
- `2`: Processing
- `3`: Pending
- `4`: Payment Complete

## Important Notes

1. Token Validity
   - Tokens remain valid until explicitly invalidated
   - All requests must include APPID, DEVICEID, and TOKEN

2. Error Handling
   - Failed requests return an error response:
   ```json
   {
       "status": "ERR",
       "err": "ERROR_CODE",
       "errm": "Error message for user"
   }
   ```

3. Display Options
   The `SHOW` parameter can control the visibility of different fields:
   - `SHOW.KIN`: Show client KIN
   - `SHOW.NAME`: Show client name
   - `SHOW.GSM`: Show client phone number
   - `SHOW.EMAIL`: Show client email
   - `SHOW.PIC`: Show client picture

4. Best Practices
   - Space out token generation requests by 20-30 seconds
   - Always verify payment status after initialization
   - Keep track of payment IDs for reconciliation
   - Monitor payment states through the status endpoint until completion

## Error Handling

All API endpoints return a standard error response format when an error occurs:

```json
{
    "status": "ERR",
    "err": "ERROR_CODE",
    "errm": "Human-readable error message"
}
```

### Common Error Codes
- `EBADT`: Invalid token or token expired
- `EBADP`: Invalid parameters
- `EBADTEN`: Invalid or expired token, device needs reauthorization

## Best Practices

1. Authentication
   - Store tokens securely
   - Implement token refresh mechanism
   - Handle token invalidation gracefully

2. Payments
   - Always verify payment status after initialization
   - Implement proper error handling for failed payments
   - Keep track of payment IDs for reconciliation
   - Monitor payment states through the status endpoint until completion

3. Security
   - Implement checksum validation for sensitive operations
   - Never store sensitive card data
   - Use HTTPS for all API calls
   - Validate all user inputs

4. User Experience
   - Space out token generation requests by 20-30 seconds
   - Provide clear error messages to users
   - Implement proper loading states during API calls
   - Handle network errors gracefully

## No Registration Payment API

The No Registration API allows processing payments without requiring users to register an ePay.bg account.

### Token Generation and Card Storage
When processing payments from unregistered users, a token is generated with validity determined by the `savecard` parameter:
- `savecard=0`: Token is valid only for the current payment
- `savecard=1`: Token is valid for all future payments

### Process Payment
```
GET /api/payment/noreg/send
```

User enters card data and chooses whether to save it for future payments. This generates a user TOKEN for payment processing.

**Parameters:**
- `APPID` (required): Application identifier
- `DEVICEID` (required): Device identifier
- `ID` (required): Payment identifier
- `AMOUNT` (required): Amount in stotinki
- `RCPT` (required): Recipient KIN
- `RCPT_TYPE` (required): Must be "KIN"
- `DESCRIPTION` (required): Payment description
- `REASON` (required): Payment reason
- `SAVECARD` (optional): Set to 1 to save card for future payments
- `checksum` (required): Request validation checksum

After entering card data, the client is redirected to the REPLY_ADDRESS.

### Check Payment Status
```
GET /api/payment/noreg/send/status
```

**Parameters:**
Same as the send request

**Success Response with saved card (savecard=1):**
```json
{
    "payment": {
        "REASON": "Transfer reason",
        "DESCRIPTION": "Transfer description",
        "AMOUNT": 10,
        "TAX": 100,
        "TOTAL": 110,
        "RCPT_TYPE": "KIN",
        "RCPT": "8897458022",
        "PAYER_KIN": "5112074184",
        "STATE": 3,
        "STATE.TEXT": "Payment Complete",
        "TOKEN": "99823906809141864859059099131376",
        "NO": "2000000000032229"
    },
    "payment_instrument": {
        "ID": "UsYGw8-pTlZU4DJOAYT911cDTSmYoCcPYIAaLZp-1FQ",
        "CARD_REF": "c8fb30bdaed9d9721b4ac215251333900548cca18575ae1f017566c12f8ee626",
        "NAME": "Visa***1111",
        "CARD_TYPE": "4",
        "TYPE": 1,
        "CARD_TYPE_DESCR": "Visa",
        "EXPIRES": "04/2020",
        "VERIFIED": 0,
        "CARD_TYPE_COUNTRY": "BG"
    },
    "savecard": 1,
    "status": "OK"
}
```

**Response Fields:**
- `TOKEN`: Can be used for future payments when savecard=1
- `payment_instrument.ID`: Must be provided as PINS parameter in subsequent payments
- `CARD_TYPE_DESCR`: Card type (e.g., "Visa", "MasterCard")
- `NO`: Transaction number

## Testing
Use the demo environment (`demo.epay.bg`) for testing before moving to production. The demo environment provides a safe way to test:
- Authentication flow
- Payment processing
- Error handling
- User information retrieval
- No Registration payments

## Support
For additional support or questions:
1. Contact ePay.bg support team
2. Refer to the official documentation
3. Check the error codes and messages for troubleshooting
