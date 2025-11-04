# Cognify Auth Website

This folder contains the authentication website implementation for Cognify that should be hosted at `ethioware.org/cognify`.

## Files

- **auth.html** - Main authentication page with Google Sign-In
- **auth.js** - JavaScript for handling OAuth flow and token exchange
- **auth.css** - Styling for the authentication page

## Deployment Instructions

### 1. Upload Files to Server

Upload all files from this folder to your web server at:
```
ethioware.org/cognify/
```

File structure should be:
```
ethioware.org/cognify/
├── auth.html
├── auth.js
└── auth.css
```

### 2. Backend API Endpoint

Ensure your backend server is accessible at:
```
https://ethioware.org/cognify/api/v1/auth/exchange
```

This endpoint should accept POST requests with:
```json
{
  "googleToken": "google-oauth-jwt-token"
}
```

And return:
```json
{
  "jwt": "backend-jwt-token",
  "user": {
    "googleId": "user-id",
    "displayName": "User Name",
    "email": "user@example.com"
  }
}
```

### 3. Google OAuth Configuration

The Google Client ID is already configured in `auth.html`:
```
615874094558-7vat1vkdihe0htgq9siljg7sgk4sjlnv.apps.googleusercontent.com
```

Make sure this Client ID is configured in Google Cloud Console with:
- **Authorized JavaScript origins**: `https://ethioware.org`
- **Authorized redirect URIs**: `https://ethioware.org/cognify/*`

### 4. CORS Configuration

If your backend API is on a different domain, ensure CORS headers are set:
```
Access-Control-Allow-Origin: https://ethioware.org
Access-Control-Allow-Methods: POST, OPTIONS
Access-Control-Allow-Headers: Content-Type
```

### 5. Testing

1. Open `https://ethioware.org/cognify/auth.html` in a browser
2. Click "Sign in with Google"
3. Complete Google authentication
4. Verify redirect with token in URL: `?token=xxx&success=true`

## How It Works

1. **Extension opens auth page**: Chrome extension opens `https://ethioware.org/cognify/auth?source=extension`

2. **User signs in**: User clicks Google Sign-In button and authenticates

3. **Token received**: Google returns a JWT credential

4. **Redirect to extension**: 
   - If opened from extension: Redirects with token in URL (`?token=xxx&success=true`)
   - Extension detects URL change and extracts token
   - Extension exchanges token for backend JWT via `/api/v1/auth/exchange`

5. **JWT stored**: Extension stores backend JWT in Chrome storage for future API calls

## Customization

- **Styling**: Modify `auth.css` to match your brand
- **Layout**: Edit `auth.html` for different layouts
- **Behavior**: Adjust `auth.js` for different flow requirements

## Troubleshooting

### Token not being detected by extension
- Ensure URL includes `?source=extension` parameter
- Check that extension has permission for `https://ethioware.org/*`
- Verify token is in URL after redirect

### CORS errors
- Check backend CORS configuration
- Ensure `Access-Control-Allow-Origin` includes your domain

### Google Sign-In not working
- Verify Client ID is correct in `auth.html`
- Check Google Cloud Console OAuth configuration
- Ensure authorized origins include `https://ethioware.org`
