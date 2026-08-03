# Smart Transaction Control with Expense Tracker

A complete, production-quality college mini project for managing personal finances with smart AI-powered analytics, multi-wallet support, budget tracking, bill reminders, and more.

## 🚀 Technology Stack

| Layer | Technology |
|-------|-----------|
| **Frontend** | HTML5, CSS3, JavaScript (Vanilla) |
| **Backend** | PHP |
| **Business Logic** | Java |
| **Analytics & Prediction** | Python |
| **Database** | MongoDB Atlas (Free Tier) |
| **Charts** | Chart.js |
| **Icons** | Font Awesome |
| **Email** | PHPMailer with Gmail SMTP |
| **Export** | PDF, CSV, Excel |

## 📁 Folder Structure

```
├── backend/
│   ├── api/                    # PHP API endpoints
│   │   ├── index.php           # Unified API router
│   │   ├── auth.php            # Authentication (login/register/OTP)
│   │   ├── transactions.php    # Transaction CRUD
│   │   ├── wallets.php         # Multi-wallet management & transfers
│   │   ├── goals.php           # Savings goals
│   │   ├── budgets.php         # Budget system
│   │   ├── reminders.php       # Bill reminders
│   │   ├── recurring.php       # Recurring transactions
│   │   ├── analytics.php       # Python AI bridge
│   │   ├── reports.php         # PDF/CSV/Excel reports
│   │   └── categories.php      # Category management
│   ├── config/                 # DB & app configuration
│   ├── services/               # EmailService (PHPMailer)
│   ├── middleware/             # Security middleware
│   └── php/                    # Shared PHP helpers
├── frontend/
│   ├── css/                    # Stylesheets (Glassmorphism, themes)
│   ├── js/                     # Frontend JavaScript
│   ├── auth/                   # Login/Register/Verify pages
│   ├── admin/                  # Admin dashboard pages
│   └── customer/               # User dashboard pages
├── python/
│   ├── ai/                     # Python AI modules
│   │   ├── spending_analysis.py      # Trend analysis & financial score
│   │   ├── expense_prediction.py     # Next month spending prediction
│   │   ├── monthly_forecast.py       # Monthly spending forecast
│   │   ├── yearly_forecast.py        # Yearly forecast
│   │   ├── anomaly_detection.py      # Unusual expense detection
│   │   ├── fraud_detection.py        # Frequent merchant detection
│   │   └── budget_recommendation.py  # Budget recommendations & saving tips
│   ├── main.py                 # Python entry point
│   └── requirements.txt
├── java/                       # Java business logic (Maven)
│   ├── pom.xml
│   └── src/main/java/com/expensetracker/
│       ├── model/Transaction.java
│       ├── service/            # Transaction, Wallet, Budget, Goal, Reminder services
│       └── ExpenseTrackerApp.java
├── database/
│   ├── schema.js               # MongoDB schema (20 collections)
│   ├── seed_data.js            # Seed data
│   ├── setup.php               # DB setup script
│   └── backup/                 # Backup strategy
├── uploads/
│   ├── receipts/               # Receipt image uploads
│   └── profile_photos/
├── config.php                  # Root configuration
├── index.php                   # Entry point
├── index.html                  # Landing page
└── composer.json               # PHP dependencies
```

## 💾 Installation Guide

### Prerequisites
- XAMPP (PHP ≥ 8.0) — [Download](https://www.apachefriends.org/)
- Python 3.8+ — [Download](https://www.python.org/)
- MongoDB Atlas account (Free Tier) — [Sign up](https://www.mongodb.com/atlas)
- Java 11+ (for business logic module)
- Composer — [Download](https://getcomposer.org/)

### Step 1: Clone & Setup
```bash
git clone https://github.com/vinoth008/sot.git MPWT
cd MPWT
composer install
```

### Step 2: Configure Environment
Copy `.env.example` to `.env` and fill in:
```env
# MongoDB Atlas connection string
MONGODB_URI=mongodb+srv://username:password@cluster.mongodb.net/expense_tracker

# SMTP (Gmail) — for OTP emails
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=your-email@gmail.com
SMTP_PASSWORD=your-app-password    # Use Google App Password
SMTP_FROM_EMAIL=your-email@gmail.com
SMTP_FROM_NAME=Smart Expense Tracker
```

### Step 3: Database Setup
```bash
# Option A: Using MongoDB shell
mongosh --uri "mongodb+srv://..." < database/schema.js
mongosh --uri "mongodb+srv://..." < database/seed_data.js

# Option B: Using the setup script
# Visit http://localhost/MPWT/database/setup.php
```

### Step 4: Run the Application
```bash
# Start XAMPP Apache + MongoDB
# Visit:
http://localhost/MPWT/
```

### Step 5: Python Setup (Analytics)
```bash
cd python
pip install -r requirements.txt
```

### Step 6: Java Business Logic
```bash
cd java
mvn clean package
java -jar target/expense-tracker-1.0-SNAPSHOT.jar
```

## 🔐 Default Accounts (from seed data)

| Role | Email | Password |
|------|-------|----------|
| Admin | admin@expensetracker.com | Admin@123 |
| User | user@expensetracker.com | User@123 |

## 📊 Features

### User Features
- ✅ Email OTP verification & forgot password
- ✅ Add/edit/delete income & expenses
- ✅ Multiple wallets (Cash, Bank, UPI, Credit/Debit cards)
- ✅ Transfer between wallets
- ✅ Monthly/category/daily budgets with progress bars
- ✅ Savings goals with estimated completion
- ✅ Recurring transactions (daily/weekly/monthly/yearly)
- ✅ Bill reminders (electricity, water, internet, etc.)
- ✅ Receipt image uploads
- ✅ Search, filter, sort, pagination
- ✅ AI-powered spending predictions & insights
- ✅ Financial health score
- ✅ Export reports (PDF/CSV/Excel)

### Admin Features
- 📊 Full dashboard with system statistics
- 👥 User management (block/unblock/delete)
- 🏷️ Category management
- 💰 Budget oversight
- 📈 Financial analytics across all users
- 📄 Report generation
- 🗄️ Database backup
- 📋 Activity logs
- 📢 Announcements
- ⚙️ System settings

## 🧠 Python AI Modules

| Module | Function |
|--------|----------|
| `spending_analysis.py` | Trend analysis, category breakdown, financial score |
| `expense_prediction.py` | Predicts next month spending using linear regression |
| `monthly_forecast.py` | Forecasts spending for upcoming months |
| `yearly_forecast.py` | Yearly spending projections |
| `anomaly_detection.py` | Flags unusual expenses (Z-score method) |
| `fraud_detection.py` | Detects frequent merchants & suspicious patterns |
| `budget_recommendation.py` | Recommends budget limits & saving tips |

## 🔒 Security Features
- Password hashing (Bcrypt, cost 10+)
- CSRF token protection
- XSS output sanitization
- Input validation
- MongoDB injection prevention (parameterized queries)
- Rate limiting for OTP (max 5 attempts)
- Secure session management
- Remember-me cookie (60 days)
- Activity logging
- Soft-delete for data recovery

## 📧 SMTP Setup (Gmail)
1. Enable 2-Factor Authentication on your Google account
2. Go to [Google App Passwords](https://myaccount.google.com/apppasswords)
3. Generate an app password for "Mail"
4. Set it as `SMTP_PASSWORD` in `.env`

## 🧪 Testing Guide
```bash
# PHP API tests
php tests/api_test.php

# Java unit tests
cd java && mvn test

# Python module tests
cd python && python main.py --test
```

API Endpoints:
```
POST /backend/api/index.php?module=auth&action=register
POST /backend/api/index.php?module=auth&action=login
POST /backend/api/index.php?module=transactions&action=create
GET  /backend/api/index.php?module=wallets&action=get_all
POST /backend/api/index.php?module=wallets&action=transfer
POST /backend/api/index.php?module=goals&action=create
POST /backend/api/index.php?module=reminders&action=create
POST /backend/api/index.php?module=recurring&action=process_due
GET  /backend/api/index.php?module=analytics&action=all_insights
GET  /backend/api/index.php?module=reports&action=export_csv
```

## 📚 Documentation
- [Architecture](documentation/architecture.md)
- [Database Design](documentation/database-design.md)
- [API Documentation](documentation/api-documentation.md)
- [Setup Guide](documentation/setup-guide.md)
- [Security Notes](documentation/security-notes.md)
- [Viva Questions](documentation/viva-questions.md)

## 📄 License
This project is built for educational purposes as a college mini project. All technologies used are free tiers.