// database/schema.js
/**
 * MongoDB Schema Definitions for Smart Transaction Control
 * This file documents the expected structure for each collection
 */
// ============================================
// 1. USERS COLLECTION
// ============================================
const usersSchema = {
    _id: ObjectId,
    email: String, // Unique, indexed
    password_hash: String, // Bcrypt hashed
    first_name: String,
    last_name: String,
    phone: String,
    profile_photo: String, // URL to uploaded file
    role: String, // 'user' | 'admin'
    status: String, // 'active' | 'suspended' | 'inactive'
    is_verified: Boolean,
    otp_code: String,
    otp_expiry: Date,
    login_attempts: Number,
    locked_until: Date,
    currency: String, // Default: 'INR'
    theme_preference: String, // 'dark' | 'light'
    created_at: Date,
    updated_at: Date,
    last_login: Date,
    deleted_at: Date // Soft delete
};
// Indexes
db.users.createIndex({ email: 1 }, { unique: true });
db.users.createIndex({ role: 1, status: 1 });
db.users.createIndex({ created_at: -1 });
// ============================================
// 2. TRANSACTIONS COLLECTION
// ============================================
const transactionsSchema = {
    _id: ObjectId,
    user_id: ObjectId, // Reference to users._id, indexed
    type: String, // 'income' | 'expense' | 'transfer' | 'loan' | 'borrow' | 'lend' | 'investment' | 'salary' | 'bonus' | 'tax'
    category: String, // 'salary' | 'bonus' | 'food' | 'travel' | 'shopping' | 'medical' | 'education' | 'entertainment' | 'fuel' | 'insurance' | 'subscriptions' | 'utilities' | 'rent' | 'emi' | 'others'
    subcategory: String,
    amount: Number,
    currency: String,
    description: String,
    date: Date, // Transaction date
    payment_method: String, // 'cash' | 'card' | 'upi' | 'bank_transfer' | 'wallet'
    recipient_payer: String,
    tags: [String],
    receipt_url: String,
    is_recurring: Boolean,
    recurring_frequency: String, // 'daily' | 'weekly' | 'monthly' | 'yearly'
    next_recurring_date: Date,
    installment_total: Number,
    installment_paid: Number,
    is_split: Boolean,
    split_with: [String],
    split_amount: Number,
    notes: String,
    is_template: Boolean,
    created_at: Date,
    updated_at: Date
};
// Indexes
db.transactions.createIndex({ user_id: 1, date: -1 });
db.transactions.createIndex({ user_id: 1, type: 1 });
db.transactions.createIndex({ user_id: 1, category: 1 });
db.transactions.createIndex({ user_id: 1, is_recurring: 1 });
db.transactions.createIndex({ date: -1 });
// ============================================
// 3. BUDGETS COLLECTION
// ============================================
const budgetsSchema = {
    _id: ObjectId,
    user_id: ObjectId,
    category: String,
    monthly_limit: Number,
    current_spent: Number,
    period_start: Date,
    period_end: Date,
    warning_threshold: Number, // Percentage (e.g., 80)
    is_active: Boolean,
    created_at: Date,
    updated_at: Date
};
// Indexes
db.budgets.createIndex({ user_id: 1, category: 1 });
db.budgets.createIndex({ user_id: 1, is_active: 1 });
// ============================================
// 4. GOALS COLLECTION
// ============================================
const goalsSchema = {
    _id: ObjectId,
    user_id: ObjectId,
    name: String,
    target_amount: Number,
    current_amount: Number,
    deadline: Date,
    priority: String, // 'low' | 'medium' | 'high'
    status: String, // 'active' | 'completed' | 'paused'
    notes: String,
    created_at: Date,
    updated_at: Date
};
// Indexes
db.goals.createIndex({ user_id: 1, status: 1 });
// ============================================
// 5. WISHLIST COLLECTION
// ============================================
const wishlistSchema = {
    _id: ObjectId,
    user_id: ObjectId,
    item_name: String,
    estimated_cost: Number,
    priority: Number, // 1-5
    notes: String,
    created_at: Date
};
// ============================================
// 6. NOTES COLLECTION
// ============================================
const notesSchema = {
    _id: ObjectId,
    user_id: ObjectId,
    title: String,
    content: String,
    tags: [String],
    color: String,
    is_pinned: Boolean,
    created_at: Date,
    updated_at: Date
};
// ============================================
// 7. CATEGORIES COLLECTION
// ============================================
const categoriesSchema = {
    _id: ObjectId,
    user_id: ObjectId, // null for system categories
    name: String,
    type: String, // 'income' | 'expense'
    icon: String,
    color: String,
    parent_category: ObjectId,
    is_system: Boolean,
    is_favorite: Boolean,
    created_at: Date
};
// Indexes
db.categories.createIndex({ user_id: 1, type: 1 });
db.categories.createIndex({ is_system: 1 });
// ============================================
// 8. ANALYTICS CACHE COLLECTION
// ============================================
const analyticsCacheSchema = {
    _id: ObjectId,
    user_id: ObjectId,
    cache_type: String, // 'daily' | 'weekly' | 'monthly' | 'yearly'
    period_start: Date,
    period_end: Date,
    data: {
        total_income: Number,
        total_expense: Number,
        savings: Number,
        savings_rate: Number,
        category_breakdown: Object,
        daily_trend: Array,
        highest_expense: Number,
        average_expense: Number,
        cash_flow: Number
    },
    generated_at: Date,
    expires_at: Date
};
// Indexes
db.analytics_cache.createIndex({ user_id: 1, cache_type: 1, period_start: 1 });
// ============================================
// 9. NOTIFICATIONS COLLECTION
// ============================================
const notificationsSchema = {
    _id: ObjectId,
    user_id: ObjectId,
    type: String, // 'budget_warning' | 'achievement' | 'reminder' | 'system'
    title: String,
    message: String,
    is_read: Boolean,
    link: String,
    created_at: Date
};
// Indexes
db.notifications.createIndex({ user_id: 1, is_read: 1, created_at: -1 });
// ============================================
// 10. ACHIEVEMENTS COLLECTION
// ============================================
const achievementsSchema = {
    _id: ObjectId,
    user_id: ObjectId,
    achievement_type: String, // 'streak' | 'savings' | 'budget_master' | etc.
    title: String,
    description: String,
    unlocked_at: Date,
    points: Number
};
// Indexes
db.achievements.createIndex({ user_id: 1, achievement_type: 1 });
// ============================================
// 11. ACTIVITY LOGS COLLECTION
// ============================================
const activityLogsSchema = {
    _id: ObjectId,
    user_id: ObjectId,
    action: String, // 'login' | 'logout' | 'create_transaction' | 'update_profile' | 'delete_account' | 'change_password' | etc.
    ip_address: String,
    user_agent: String,
    timestamp: Date,
    details: Object
};
// Indexes
db.activity_logs.createIndex({ user_id: 1, timestamp: -1 });
db.activity_logs.createIndex({ action: 1, timestamp: -1 });
// ============================================
// 12. AUDIT LOGS COLLECTION (Admin)
// ============================================
const auditLogsSchema = {
    _id: ObjectId,
    admin_id: ObjectId,
    action: String,
    target_user_id: ObjectId,
    details: Object,
    ip_address: String,
    timestamp: Date
};
// Indexes
db.audit_logs.createIndex({ admin_id: 1, timestamp: -1 });
// ============================================
// 13. SYSTEM SETTINGS COLLECTION
// ============================================
const systemSettingsSchema = {
    _id: ObjectId,
    setting_key: String, // Unique
    setting_value: Mixed,
    description: String,
    updated_by: ObjectId,
    updated_at: Date
};
// Indexes
db.system_settings.createIndex({ setting_key: 1 }, { unique: true });
// ============================================
// 14. FEEDBACK COLLECTION
// ============================================
const feedbackSchema = {
    _id: ObjectId,
    user_id: ObjectId,
    subject: String,
    message: String,
    rating: Number, // 1-5
    status: String, // 'pending' | 'resolved' | 'closed'
    admin_response: String,
    created_at: Date,
    updated_at: Date
};
// ============================================
// 15. SESSIONS COLLECTION (Optional for distributed sessions)
// ============================================
const sessionsSchema = {
    _id: ObjectId,
    session_id: String, // Unique
    user_id: ObjectId,
    data: Object,
    expires_at: Date,
    created_at: Date
};
// Indexes
db.sessions.createIndex({ session_id: 1 }, { unique: true });
db.sessions.createIndex({ expires_at: 1 }, { expireAfterSeconds: 0 });
// ============================================
// 16. WALLETS COLLECTION
// ============================================
const walletsSchema = {
    _id: ObjectId,
    user_id: ObjectId, // Reference to users._id, indexed
    name: String, // e.g. 'Cash', 'HDFC Bank', 'Paytm'
    balance: Number, // Current wallet balance
    currency: String, // 'INR'
    icon: String, // Font Awesome icon
    color: String, // Display color
    description: String,
    is_default: Boolean,
    created_at: Date,
    updated_at: Date,
    deleted_at: Date // Soft delete
};
// Indexes
db.wallets.createIndex({ user_id: 1, deleted_at: 1 });
db.wallets.createIndex({ user_id: 1, name: 1 }, { unique: true, partialFilterExpression: { deleted_at: null } });
// ============================================
// 17. WALLET TRANSFERS COLLECTION
// ============================================
const walletTransfersSchema = {
    _id: ObjectId,
    user_id: ObjectId,
    from_wallet_id: ObjectId,
    to_wallet_id: ObjectId,
    from_wallet_name: String,
    to_wallet_name: String,
    amount: Number,
    description: String,
    created_at: Date,
    deleted_at: Date
};
// Indexes
db.wallet_transfers.createIndex({ user_id: 1, created_at: -1 });
db.wallet_transfers.createIndex({ from_wallet_id: 1 });
db.wallet_transfers.createIndex({ to_wallet_id: 1 });
// ============================================
// 18. REMINDERS COLLECTION
// ============================================
const remindersSchema = {
    _id: ObjectId,
    user_id: ObjectId,
    title: String, // e.g. 'Electricity Bill'
    type: String, // 'electricity' | 'water' | 'internet' | 'gas' | 'mobile_recharge' | 'credit_card' | 'emi' | 'insurance' | 'rent' | 'other'
    amount: Number,
    due_date: Date,
    repeat: String, // 'none' | 'daily' | 'weekly' | 'monthly' | 'yearly'
    days_before_notify: Number, // Notify N days before due
    category: String,
    notes: String,
    is_active: Boolean,
    is_paid: Boolean,
    paid_date: Date,
    last_notified_at: Date,
    notification_sent: Boolean,
    created_at: Date,
    updated_at: Date,
    deleted_at: Date
};
// Indexes
db.reminders.createIndex({ user_id: 1, due_date: 1 });
db.reminders.createIndex({ user_id: 1, is_active: 1, is_paid: 1 });
// ============================================
// 19. RECURRING TRANSACTIONS COLLECTION
// ============================================
const recurringTransactionsSchema = {
    _id: ObjectId,
    user_id: ObjectId,
    name: String,
    type: String, // 'income' | 'expense'
    category: String,
    amount: Number,
    currency: String,
    frequency: String, // 'daily' | 'weekly' | 'monthly' | 'yearly'
    interval: Number, // Every N periods
    start_date: Date,
    end_date: Date, // null = no end
    wallet_id: ObjectId, // Optional linked wallet
    payment_method: String,
    merchant: String,
    description: String,
    tags: [String],
    is_active: Boolean,
    next_run_date: Date,
    last_run_date: Date,
    times_generated: Number,
    created_at: Date,
    updated_at: Date,
    deleted_at: Date
};
// Indexes
db.recurring_transactions.createIndex({ user_id: 1, is_active: 1 });
db.recurring_transactions.createIndex({ next_run_date: 1 });
// ============================================
// 20. OTP RECORDS COLLECTION
// ============================================
const otpRecordsSchema = {
    _id: ObjectId,
    email: String, // Indexed - the email the OTP was sent to
    user_id: ObjectId, // Optional for logged-in requests
    otp_code: String, // Hashed OTP
    purpose: String, // 'verify_email' | 'forgot_password' | 'login'
    expires_at: Date,
    attempts: Number, // Tracks verification attempts
    is_used: Boolean,
    ip_address: String,
    user_agent: String,
    created_at: Date
};
// Indexes
db.otp_records.createIndex({ email: 1, purpose: 1, created_at: -1 });
db.otp_records.createIndex({ email: 1, otp_code: 1, is_used: 1 });
db.otp_records.createIndex({ expires_at: 1 }, { expireAfterSeconds: 0 }); // Auto-delete expired OTPs
