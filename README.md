# 💳 Credit Enroll Pro

> **Enterprise-grade enrollment platform for credit repair services**

[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![License](https://img.shields.io/badge/license-Proprietary-red.svg)](LICENSE)
[![TransXacto](https://img.shields.io/badge/Part%20of-TransXacto%20Suite-9c6046)](https://transxacto.net)

**Credit Enroll Pro** is a secure, feature-rich web application designed specifically for credit repair businesses to streamline client enrollment, manage contracts, and integrate with essential business tools. Built with enterprise-grade security and compliance in mind.

[Features](#-features) • [Quick Start](#-quick-start) • [Integrations](#-integrations) • [Documentation](#-documentation) • [Support](#-support)

---

## ✨ Features

### 🎯 Client Experience

| Feature | Description |
|---------|-------------|
| **📝 Multi-Step Enrollment** | Intuitive wizard-style enrollment with save & resume functionality |
| **🔐 Session Management** | Unique session IDs (`ABCD-1234`) for easy enrollment resumption |
| **📱 2FA Authentication** | SMS & email verification for returning clients |
| **✍️ Digital Signatures** | Canvas-based electronic signature capture |
| **📍 Smart Address Entry** | Google Maps autocomplete for accurate address collection |
| **👥 Spouse Enrollment** | Dual or separate enrollment options for couples |
| **📄 Document Upload** | Encrypted ID verification document storage |
| **📱 Mobile Optimized** | Fully responsive design for any device |

### 🔒 Security & Compliance

- **AES-256-CBC Encryption** for sensitive data (SSN, documents)
- **Argon2ID Password Hashing** for staff accounts
- **2FA/MFA Support** (TOTP, SMS, Email) for admin access
- **reCAPTCHA Enterprise** bot protection
- **Audit Logging** for all critical actions
- **IP Tracking** and device fingerprinting
- **HTTPS Enforced** with secure session management

### 🎛️ Admin Dashboard

- 📊 **Real-time Analytics** - Enrollment metrics and conversion tracking
- 🔍 **Advanced Search** - Find clients by name, email, phone, or session ID
- 📧 **Smart Notifications** - Alerts for stalled enrollments (5+ min inactive)
- 👥 **Staff Management** - Role-based access control with 2FA
- 💰 **Plan Management** - Create and manage pricing plans
- 🏷️ **Affiliate Tracking** - Track referrals and commission
- ❓ **Question Builder** - Custom enrollment questions (yes/no, multiple choice, text)
- 📝 **Contract Management** - State-specific agreement templates
- 🎨 **Brand Customization** - Custom logos, colors, and branding

### 🔗 Integrations

Credit Enroll Pro integrates seamlessly with industry-leading platforms:

<table>
<tr>
<td width="33%" align="center">
<h4>🔐 Authentication</h4>
<p><strong>XactoAuth</strong><br/>Single Sign-On (SSO)<br/>Enterprise authentication</p>
</td>
<td width="33%" align="center">
<h4>📧 Communications</h4>
<p>
  <a href="https://voip.ms/en/invite/NDUxNjA1" target="_blank" rel="noopener noreferrer">
    <strong>VoIP.ms</strong><br/></a>
    SMS notifications<br/>
    Two-factor authentication codes
</p>
<p><strong>MailerSend</strong><br/>Transactional emails<br/>Enrollment notifications</p>
</td>
<td width="33%" align="center">
<h4>🗺️ Google Services</h4>
<p><strong>Google Maps API</strong><br/>Address autocomplete<br/>Location validation</p>
<p><strong>reCAPTCHA Enterprise</strong><br/>Bot protection<br/>Fraud prevention</p>
<p><strong>Google Analytics</strong><br/>Usage tracking<br/>Conversion metrics</p>
</td>
</tr>
<tr>
<td width="33%" align="center">
<h4>💼 CRM & Business</h4>
<p><strong>Credit Repair Cloud</strong><br/>Client management<br/>Automated lead creation</p>
</td>
<td width="33%" align="center">
<h4>💰 Financial</h4>
<p><strong>Zoho Books</strong><br/>Invoicing & billing<br/>Financial tracking</p>
</td>
<td width="33%" align="center">
<h4>📈 Marketing</h4>
<p><strong>Systeme.io</strong><br/>Marketing automation<br/>Contact management</p>
</td>
</tr>
</table>

---

## 🚀 Quick Start

### Prerequisites

- PHP 7.4+ (8.0+ recommended)
- MySQL 5.7+ / MariaDB 10.2+
- Apache or Nginx with mod_rewrite
- SSL certificate (HTTPS required)
- OpenSSL, PDO MySQL, GD/Imagick extensions

### Installation

```bash
# 1. Clone the repository
git clone https://github.com/ftllc/credit-enroll-pro.git
cd credit-enroll-pro

# 2. Copy configuration template
cp public_html/src/config.example.php public_html/src/config.php

# 3. Create required directories
mkdir -p logs public_html/src/img public_html/src/agreements
chmod 755 logs public_html/src/img

# 4. Run the setup wizard
# Navigate to: https://yourdomain.com/setup/setup.php
```

The setup wizard will:
1. ✅ Collect and test database credentials
2. ✅ Create all required database tables
3. ✅ Generate a secure encryption key
4. ✅ Create your first admin account

### Configuration

After setup, configure your integrations in `config.php` or via the admin panel:

```php
// Example API configuration
define('GOOGLE_MAPS_API_KEY', 'your_key_here');
define('RECAPTCHA_SITE_KEY', 'your_key_here');
define('VOIPMS_API_USER', 'your_username');
define('MAILERSEND_API_TOKEN', 'your_token');
```

See full configuration guide in [docs/CONFIGURATION.md](docs/CONFIGURATION.md)

---

## 📖 Documentation

- **[Installation Guide](docs/INSTALLATION.md)** - Detailed setup instructions
- **[Configuration Reference](docs/CONFIGURATION.md)** - All settings explained
- **[API Integrations](docs/INTEGRATIONS.md)** - Integration setup guides
- **[Security Best Practices](docs/SECURITY.md)** - Hardening your installation
- **[Troubleshooting](docs/TROUBLESHOOTING.md)** - Common issues and solutions

---

## 🗂️ Project Structure

```
credit-enroll-pro/
├── public_html/
│   ├── admin/              # Admin panel
│   │   ├── panel.php       # Dashboard
│   │   ├── settings.php    # System settings
│   │   ├── staff.php       # Staff management
│   │   └── ...
│   ├── enroll/             # Client enrollment flow
│   │   ├── index.php       # Enrollment wizard
│   │   ├── contracts.php   # Contract review & signing
│   │   └── ...
│   ├── continue/           # Resume enrollment
│   ├── noc/                # Notice of cancellation
│   └── src/
│       ├── config.example.php  # Configuration template
│       ├── header.php          # Global header
│       ├── footer.php          # Global footer
│       └── ...
│   ├── setup/
│   │   ├── setup.php           # Installation wizard
│   │   └── database_schema.sql # Database structure
├── logs/                   # Application logs (gitignored)
└── README.md
```

---

## 🔄 Enrollment Flow

1. **👋 Welcome** - Name, contact info, referral code
2. **❓ Questions** - Custom enrollment questions (if enabled)
3. **🏠 Address** - Home address with Google Maps autocomplete
4. **💳 Plan Selection** - Individual or couple plans
5. **👥 Add Spouse** - Spouse information (if applicable)
6. **📄 Contracts** - Review and electronically sign agreements
7. **✅ Review** - Confirm all information
8. **🎉 Congratulations** - Download signed contract package
9. **🔐 Personal Info** - DOB, SSN (optional expedite service)
10. **📤 Upload Documents** - ID verification (optional)
11. **🙏 Thank You** - Enrollment complete!

---

## 🛡️ Security Features

### Data Protection
- **AES-256-CBC encryption** for SSN and sensitive documents
- **Argon2ID password hashing** (PHC winner, OWASP recommended)
- **HTTPS enforced** for all traffic
- **Secure session management** with regeneration

### Access Control
- **Role-based permissions** (Admin, Manager, Staff)
- **2FA/MFA support** (TOTP, SMS, Email)
- **XactoAuth SSO** integration for enterprise authentication
- **IP tracking** and device fingerprinting

### Compliance
- **Audit logging** for all sensitive operations
- **Activity tracking** with timestamps
- **Document encryption** at rest
- **Configurable data retention** policies

---

## 🔧 Key Technologies

- **Backend**: PHP 7.4+ with PDO
- **Database**: MySQL 5.7+ / MariaDB 10.2+
- **PDF Generation**: FPDF library
- **Authentication**: XactoAuth SSO (optional)
- **Email**: MailerSend API
- **SMS**: VoIP.ms API
- **Maps**: Google Maps JavaScript API
- **Security**: reCAPTCHA Enterprise

---

## 🤝 Part of TransXacto Suite

Credit Enroll Pro is part of the **TransXacto business automation ecosystem**:

- **[XactoAuth](https://auth.transxacto.net)** - Enterprise authentication platform
- **[XactoComms](https://comms.transxacto.net)** - Unified communications hub
- **[XactoSign](https://xs.transxacto.net)** - Digital signature platform
- **[TransXacto CTRL](https://ctrl.transxacto.net)** - Subscription management

---

## 📊 Database Schema

Credit Enroll Pro uses **21 tables** to manage:

- **Enrollment tracking** - `enrollment_users`, `enrollment_steps`, `enrollment_question_responses`
- **Plans & pricing** - `plans`
- **Contracts & documents** - `contracts`, `id_docs`, `state_specific_contracts`
- **Staff & authentication** - `staff`, `2fa_codes`, `trusted_browsers`
- **Affiliates** - `affiliates`
- **Integrations** - `api_keys`
- **Settings** - `settings`, `enrollment_questions`
- **Notes & communication** - `notes`, `email_templates`, `sms_templates`

See full schema in [public_html/setup/database_schema.sql](public_html/setup/database_schema.sql)

---

## 🐛 Troubleshooting

### Database Connection Fails
- Verify credentials in `config.php`
- Check MySQL server is running
- Ensure database user has proper permissions

### Enrollment Not Starting
- Check `settings` table: `enrollments_enabled` = `true`
- Verify reCAPTCHA is configured
- Check browser console for JavaScript errors

### 2FA Codes Not Sending
- Configure VoIP.ms credentials for SMS
- Configure MailerSend credentials for email
- Check logs in `/logs/` directory

See full troubleshooting guide in [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md)

---

## 📝 License

**Proprietary License** - All rights reserved.

This software is licensed for use by authorized parties only. Redistribution, modification, or use outside of the terms of your license agreement is strictly prohibited.

For licensing inquiries, contact: [licensing@transxacto.net](mailto:licensing@transxacto.net)

---

## 💬 Support

Need help? We're here for you:

- 📧 **Email**: [support@transxacto.net](mailto:support@transxacto.net)
- 📚 **Documentation**: [docs.transxacto.net](https://docs.transxacto.net)
- 🐛 **Bug Reports**: [GitHub Issues](https://github.com/ftllc/credit-enroll-pro/issues)
- 💡 **Feature Requests**: [GitHub Discussions](https://github.com/ftllc/credit-enroll-pro/discussions)

---

## 🌟 Credits

**Credit Enroll Pro** is developed and maintained by [TransXacto Networks](https://transxacto.net).

Built with ❤️ for credit repair professionals.

---

<div align="center">

**[⬆ back to top](#-credit-enroll-pro)**

Made with 💼 by [TransXacto Networks](https://transxacto.net)

</div>
