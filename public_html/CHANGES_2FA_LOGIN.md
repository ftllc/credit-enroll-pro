# 2FA Login Flow Enhancements

## Summary
Updated the admin login flow to prompt users for their preferred 2FA method (Email, SMS, or TOTP) and added a "Save Browser for 7 Days" option to skip 2FA on trusted devices.

## Changes Made

### 1. Login Flow Updates ([admin/login.php](admin/login.php))

#### Initial Login (Step 1)
- Added check for trusted browser cookie before requiring 2FA
- If valid trusted browser cookie found, user logs in directly without 2FA
- Stores staff email and phone in session for later use

#### 2FA Method Selection (Step 2A - NEW)
- Users are now prompted to choose their preferred 2FA method
- Available options shown based on what they have enabled:
  - TOTP (Authenticator App)
  - SMS (Text Message)
  - Email
- For SMS/Email methods, code is sent after selection
- For TOTP, proceeds directly to verification

#### 2FA Verification (Step 2B)
- User enters the 6-digit verification code
- New checkbox: "Save this browser for 7 days"
  - When checked, creates a trusted browser token
  - Stores token in database with:
    - Unique 64-character token
    - IP address
    - User agent
    - Expiration date (7 days from login)
  - Sets secure HTTP-only cookie with the token
- On successful verification, completes login

### 2. Database Changes

#### New Table: `trusted_browsers`
```sql
CREATE TABLE trusted_browsers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id INT NOT NULL,
    browser_token VARCHAR(64) UNIQUE NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    expires_at TIMESTAMP NOT NULL,
    revoked BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ...
)
```

**Migration File:** [setup/add_trusted_browsers_table.sql](setup/add_trusted_browsers_table.sql)

**To apply migration:**
```bash
mysql -u [username] -p [database] < setup/add_trusted_browsers_table.sql
```

### 3. UI/UX Improvements

#### Method Selection Interface
- Radio button selection with icons for each method
- Visual feedback on hover and selection
- Clear labeling (e.g., "Authenticator App (TOTP)", "Text Message (SMS)", "Email")

#### Save Browser Checkbox
- Prominent checkbox with explanatory text
- Warning: "Only use on trusted devices"
- Clear indication that it's valid for 7 days

#### Styling
- New CSS classes for method selection
- Improved checkbox styling
- Responsive design maintained

### 4. Security Features

#### Browser Token
- 64-character random hex string (256 bits of entropy)
- Stored with HTTP-only, Secure, SameSite=Lax cookie flags
- Tied to specific staff member

#### Token Validation
- Checks token exists and matches staff_id
- Verifies token hasn't expired
- Ensures token hasn't been revoked
- Updates last_used timestamp on successful validation

#### Session Variables
Temporary session variables used during 2FA flow:
- `temp_staff_id` - Staff member ID
- `temp_staff_username` - Username
- `temp_staff_email` - Email address
- `temp_staff_phone` - Phone number
- `temp_2fa_methods` - Available 2FA methods array
- `temp_2fa_method` - Selected method
- `show_method_selection` - Whether to show method selection screen

All cleaned up after successful login.

## User Flow

### Without Trusted Browser
1. Enter username and password
2. **[NEW]** Select 2FA method (TOTP, SMS, or Email)
3. Enter verification code
4. **[NEW]** Optionally check "Save this browser for 7 days"
5. Login complete

### With Trusted Browser
1. Enter username and password
2. Login complete (2FA skipped)

## Testing Checklist

- [ ] Login with TOTP-enabled account
- [ ] Login with SMS-enabled account
- [ ] Login with Email-enabled account
- [ ] Login with multiple 2FA methods enabled (should see selection)
- [ ] Test "Save Browser" checkbox functionality
- [ ] Verify trusted browser bypasses 2FA on next login
- [ ] Verify trusted browser expires after 7 days
- [ ] Test on different browsers (should require 2FA again)
- [ ] Test resend code functionality
- [ ] Test invalid code attempts

## Future Enhancements

Potential improvements for consideration:
- Admin panel to view/revoke trusted browsers
- Email notification when new browser is trusted
- Option to customize trust duration (7/14/30 days)
- Ability for users to manage their own trusted devices
- Geolocation tracking for trusted browsers
- Browser fingerprinting for enhanced security

## Files Modified

1. `/admin/login.php` - Main login file with 2FA flow
2. `/setup/database_schema.sql` - Added trusted_browsers table definition
3. `/setup/add_trusted_browsers_table.sql` - Migration file (NEW)
4. `/CHANGES_2FA_LOGIN.md` - This documentation file (NEW)
