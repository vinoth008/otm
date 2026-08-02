# database/backup_strategy.md
## MongoDB Atlas Backup Strategy

### 1. Automated Backups (Atlas Native)
MongoDB Atlas provides automated continuous backups:
- **Frequency**: Every 6 hours
- **Retention**: 7 days (default), configurable up to 35 days
- **Point-in-time recovery**: Available

**Configuration**:
1. Go to Atlas Dashboard → Clusters → Backup
2. Enable Continuous Backups
3. Set retention period to 14 days
4. Enable point-in-time recovery

### 2. Manual Backup Scripts

#### Full Database Backup
```bash
mongodump --uri="mongodb+srv://username:password@cluster0.xxxxx.mongodb.net/smart_transaction_control" \
  --out=./backups/full_backup_$(date +%Y%m%d_%H%M%S)
```

#### Collection-wise Backup
```bash
# Backup users collection
mongodump --uri="mongodb+srv://..." --collection=users --out=./backups/users
# Backup transactions collection
mongodump --uri="mongodb+srv://..." --collection=transactions --out=./backups/transactions
```

### 3. Restore Procedures

#### Full Restore
```bash
mongorestore --uri="mongodb+srv://..." ./backups/full_backup_YYYYMMDD_HHMMSS/
```

#### Single Collection Restore
```bash
mongorestore --uri="mongodb+srv://..." --collection=users ./backups/users/users.bson
```

### 4. Backup Schedule
- Automated Atlas Backups: Continuous (every 6 hours)
- Manual Full Backup: Weekly (every Sunday 2 AM IST)
- Pre-deployment Backup: Before any production deployment
- Post-migration Backup: After major data migrations

### 5. Backup Verification
Monthly backup restoration test:
1. Create test database
2. Restore from latest backup
3. Verify document counts
4. Run sample queries
5. Delete test database

### 6. Disaster Recovery
1. Data Loss: Restore from latest backup
2. Corruption: Use point-in-time recovery to last known good state
3. Region Failure: Atlas multi-region replication handles this

### 7. Security
- Backup files encrypted at rest (Atlas default)
- Access restricted to admin role only
- Backup logs stored separately
