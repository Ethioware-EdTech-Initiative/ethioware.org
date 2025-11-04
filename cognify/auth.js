// Backend API URL
const BACKEND_URL = 'https://ethioware.org/cognify';

// Google OAuth Client ID
const GOOGLE_CLIENT_ID = '615874094558-7vat1vkdihe0htgq9siljg7sgk4sjlnv.apps.googleusercontent.com';

let statusMessageEl = null;
let googleScriptLoaded = false;

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
  console.log('Cognify Auth: Initializing...');
  
  // Get status message element
  statusMessageEl = document.getElementById('status-message');
  if (!statusMessageEl) {
    console.error('Cognify Auth: status-message element not found in HTML!');
    // Try to create it if it doesn't exist
    const authCard = document.querySelector('.auth-card');
    if (authCard) {
      statusMessageEl = document.createElement('div');
      statusMessageEl.id = 'status-message';
      authCard.appendChild(statusMessageEl);
    } else {
      console.error('Cognify Auth: Cannot find auth-card element!');
      return;
    }
  }
  
  // Wait for Google Identity Services script to load
  if (typeof google !== 'undefined' && google.accounts) {
    googleScriptLoaded = true;
    initializeGoogleSignIn();
  } else {
    // Wait for the script to load
    const checkGoogleScript = setInterval(() => {
      if (typeof google !== 'undefined' && google.accounts) {
        googleScriptLoaded = true;
        clearInterval(checkGoogleScript);
        initializeGoogleSignIn();
      }
    }, 100);
    
    // Timeout after 5 seconds
    setTimeout(() => {
      if (!googleScriptLoaded) {
        clearInterval(checkGoogleScript);
        console.error('Cognify Auth: Google Identity Services failed to load');
        showStatus('Error: Google Sign-In failed to load. Please refresh the page.', 'error');
      }
    }, 5000);
  }
});

function initializeGoogleSignIn() {
  const container = document.getElementById('google-signin-container');
  
  if (!container) {
    console.error('Cognify Auth: google-signin-container element not found!');
    showStatus('Error: Page elements not loaded properly. Please refresh.', 'error');
    return;
  }
  
  if (typeof google === 'undefined' || !google.accounts) {
    console.error('Cognify Auth: Google Identity Services not loaded!');
    showStatus('Error: Google Sign-In not available. Please refresh the page.', 'error');
    return;
  }
  
  try {
    google.accounts.id.initialize({
      client_id: GOOGLE_CLIENT_ID,
      callback: handleCredentialResponse,
      context: 'signin',
      ux_mode: 'popup',
    });
    
    // Render the default Google Sign-In button
    google.accounts.id.renderButton(
      container,
      {
        type: 'standard',
        theme: 'filled_blue',
        size: 'large',
        text: 'signin_with',
        width: '300'
      }
    );
    
    // Offer one-tap sign-in
    google.accounts.id.prompt((notification) => {
      if (notification.isNotDisplayed() || notification.isSkippedMoment()) {
        console.log('Cognify Auth: One-tap not displayed');
      }
    });
  } catch (error) {
    console.error('Cognify Auth: Error rendering Google Sign-In button:', error);
    showStatus('Error initializing Google Sign-In. Please refresh the page.', 'error');
  }
}

function handleCredentialResponse(response) {
  console.log('Cognify Auth: Google credential received');
  
  // Check if we can safely manipulate DOM
  if (!document || !document.body || document.readyState === 'unloading') {
    console.warn('Cognify Auth: Document not ready for DOM manipulation');
    // Still proceed with auth, just don't show status
  } else {
    showStatus('Authenticating...', 'info');
  }
  
  // Exchange Google token for backend JWT
  exchangeTokenForJWT(response.credential)
    .then((result) => {
      if (result.success) {
        console.log('Cognify Auth: Token exchange successful');
        
        // Check if we're being called from the extension
        const urlParams = new URLSearchParams(window.location.search);
        const source = urlParams.get('source');
        
        if (source === 'extension') {
          // Redirect back to extension with token
          try {
            const redirectUrl = new URL(window.location.origin + window.location.pathname);
            redirectUrl.searchParams.set('token', result.token);
            redirectUrl.searchParams.set('success', 'true');
            
            // Show status, but don't fail if DOM is unavailable
            try {
              const successMessage = result.isNewUser 
                ? 'Account created successfully! Redirecting...' 
                : 'Authentication successful! Redirecting...';
              showStatus(successMessage, 'success');
            } catch (statusError) {
              console.log('Cognify Auth: Authentication successful! Redirecting...');
            }
            
            setTimeout(() => {
              try {
                window.location.href = redirectUrl.toString();
              } catch (redirectError) {
                console.error('Cognify Auth: Redirect error:', redirectError);
              }
            }, 1000);
          } catch (urlError) {
            console.error('Cognify Auth: URL construction error:', urlError);
            showStatus('Authentication successful! Please close this tab.', 'success');
          }
        } else {
          // For web-only use, store token in localStorage and redirect
          try {
            localStorage.setItem('cognify_jwt', result.jwt);
            localStorage.setItem('cognify_user', JSON.stringify(result.user));
            
            // Show status, but don't fail if DOM is unavailable
            try {
              showStatus('Authentication successful!', 'success');
            } catch (statusError) {
              console.log('Cognify Auth: Authentication successful!');
            }
            
            setTimeout(() => {
              try {
                window.location.href = '/cognify/dashboard.html';
              } catch (redirectError) {
                console.error('Cognify Auth: Redirect error:', redirectError);
              }
            }, 1500);
          } catch (storageError) {
            console.error('Cognify Auth: Storage error:', storageError);
            showStatus('Authentication successful!', 'success');
          }
        }
      } else {
        throw new Error(result.error || 'Authentication failed');
      }
    })
    .catch((error) => {
      console.error('Cognify Auth: Token exchange error:', error);
      showStatus(`Authentication failed: ${error.message}`, 'error');
    });
}

async function exchangeTokenForJWT(googleToken) {
  try {
    const response = await fetch(`${BACKEND_URL}/api/v1/auth/exchange`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        googleToken: googleToken,
      }),
    });
    
    if (!response.ok) {
      const errorText = await response.text();
      throw new Error(`Server error: ${response.status} - ${errorText}`);
    }
    
    const data = await response.json();
    
    if (!data.jwt || !data.user) {
      throw new Error('Invalid response from server');
    }
    
    // Return token for redirect (we'll generate a short-lived token for the redirect)
    // In production, you might want to use a different approach
    return {
      success: true,
      jwt: data.jwt,
      user: data.user,
      isNewUser: data.isNewUser || false,
      token: btoa(JSON.stringify({ jwt: data.jwt, user: data.user })), // Simple encoding for URL param
    };
  } catch (error) {
    console.error('Cognify Auth: Exchange error:', error);
    return {
      success: false,
      error: error.message,
    };
  }
}

function showStatus(message, type = 'info') {
  // Check if document is still available (might be during page unload)
  if (!document || !document.body) {
    console.log('Cognify Auth: Cannot show status - document not available');
    return;
  }
  
  // Ensure we have the status element - always get fresh reference
  statusMessageEl = document.getElementById('status-message');
  
  // If still not found, try to create it
  if (!statusMessageEl) {
    const authCard = document.querySelector('.auth-card');
    if (authCard && authCard.parentNode) {
      statusMessageEl = document.createElement('div');
      statusMessageEl.id = 'status-message';
      authCard.appendChild(statusMessageEl);
    } else {
      console.warn('Cognify Auth: Cannot show status - no container found');
      // Log message to console as fallback
      console.log(`Cognify Auth Status [${type}]: ${message}`);
      return;
    }
  }
  
  // Verify element is still in DOM before manipulating
  if (!statusMessageEl.parentNode) {
    console.warn('Cognify Auth: Status element removed from DOM');
    return;
  }
  
  // Now safely set the status
  try {
    if (statusMessageEl && statusMessageEl.parentNode) {
      statusMessageEl.textContent = message;
      statusMessageEl.className = `status status-${type}`;
      statusMessageEl.style.display = 'block';
      
      // Auto-hide info messages after 5 seconds
      if (type === 'info') {
        setTimeout(() => {
          const el = document.getElementById('status-message');
          if (el && el.parentNode) {
            el.style.display = 'none';
          }
        }, 5000);
      }
    }
  } catch (error) {
    console.error('Cognify Auth: Error showing status:', error);
    // Fallback to console log
    console.log(`Cognify Auth Status [${type}]: ${message}`);
  }
}

