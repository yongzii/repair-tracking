# Repair Service Management System

A professional, enterprise-grade repair shop management system designed for electronics repair operations. This system helps manage repair jobs, track costs, manage payments, and keep customers informed in real-time.

## 🎯 Key Features

### 1. **Job Order Management**
- Unique auto-generated job order numbers (RSMS-YYYY-XXXXXX format)
- Complete device information tracking (brand, model, serial number)
- Detailed problem description documentation
- Full audit trail with automatic status history

### 2. **Real-Time Status Tracking**
- 7 status levels: RECEIVED → DIAGNOSING → WAITING_PARTS → IN_PROGRESS → READY_FOR_PICKUP → PICKED_UP
- Alternative: UNREPAIRABLE (can be set at any time)
- Customers can check status anytime by job order number
- Status changes are automatically logged with timestamps

### 3. **Priority Service Levels**
- **STANDARD** - Regular repair (3-5 days)
- **EXPRESS** - Faster service (1-2 days)
- **EMERGENCY** - Same-day/next-day service
- Different pricing tiers for each level

### 4. **Financial Management**
- **Cost Tracking**: Parts cost + Labor cost = Total cost
- **Payment Tracking**: Track who paid, when, and how much
- **Payment Statuses**: PENDING → PARTIAL → PAID
- **Balance Calculation**: Automatically shows how much customer owes
- **Profit Margin Analysis**: Track business profitability

### 5. **Warranty Management**
- Set warranty periods per job (default: 30 days)
- Automatic warranty expiry calculation
- Track which repairs are under warranty
- Support for warranty claims and follow-ups

### 6. **Pickup Tracking**
- Record who picked up the device
- Automatic timestamp when picked up
- Automatic status change to PICKED_UP
- Just like Shopee/delivery platforms!

### 7. **Admin Dashboard**
Real-time metrics visible at a glance:
- **Job Statistics**: Total jobs, in progress, completed
- **Financial KPIs**: Total revenue, amount paid, pending payments, profit margin
- **Performance Metrics**: Completion rate, average turnaround time
- **Service Mix**: Express vs Standard jobs breakdown
- **Charts**: Device type distribution, status distribution, monthly trends

### 8. **Advanced Reporting**
- Monthly comparison reports
- Device breakdown analytics
- Status distribution visualization
- Turnaround time analysis
- Financial performance tracking

### 9. **Staff Management**
- Add and manage technicians
- Assign technicians to jobs
- Track technician specialties
- Active/Inactive status management

### 10. **Customer Experience**
- Public tracking page (no login required)
- Customers search by job order number
- See complete repair timeline
- View current status with icons
- Track pickup information

## 📊 Dashboard Metrics

### Main KPIs
- **Total Jobs**: All repair jobs in system
- **In Progress**: Currently being repaired
- **Picked Up**: Completed and collected
- **Completion Rate**: % of finished repairs
- **Total Revenue**: All costs charged

### Financial Metrics
- **Amount Paid**: Total received from customers
- **Pending Payments**: Outstanding balances
- **Profit Margin**: Revenue after parts cost
- **Average Turnaround**: Days from receipt to pickup

## 💰 Cost Management

### How It Works:
1. Create job order with estimated cost
2. During repair, update actual parts & labor costs
3. Total cost calculated automatically
4. Record customer payments as received
5. System tracks outstanding balance
6. See profit margin in real-time

### Example:
```
Repair Job: RSMS-2025-ABC123
Parts Cost:    ₱500
Labor Cost:    ₱1,000
Total Cost:    ₱1,500

Amount Paid:   ₱800
Payment Status: PARTIAL
Balance:       ₱700
```

## 🛠️ How to Use

### For Admin (Shop Owner/Staff)

**Creating a Job Order:**
1. Click "New Job Order"
2. Enter customer & device info
3. Describe the problem
4. Set priority (Standard/Express/Emergency)
5. Set warranty period (default 30 days)
6. Save (system auto-generates job number)

**Updating Status:**
1. Open job order
2. Select new status
3. Add notes (visible to customer)
4. Click "Update Status"
5. Status history updated automatically

**Recording Costs:**
1. Open job order
2. Scroll to "Financial Details"
3. Enter parts cost & labor cost
4. Click "Update Costs"
5. Total calculated automatically

**Recording Payments:**
1. Open job order
2. Click "Record Payment"
3. Enter amount paid
4. Add payment notes (optional)
5. Payment status updates automatically

**Marking Pickup:**
1. Set status to "Ready for Pickup"
2. When customer arrives, click "Mark as Picked Up"
3. Enter person's name who picked up
4. System auto-records pickup timestamp
5. Status becomes PICKED_UP
6. Customer sees notification

### For Customers (Public Tracking)

1. Go to **http://yoursite.com/track**
2. Enter job order number
3. View:
   - Current status with icon
   - Device information
   - Problem description
   - Complete timeline of all status updates
   - When device was picked up

## 🔐 Security

- Admin panel requires login (default: admin@example.com / password)
- Customer tracking page is public (no login needed)
- All transactions logged with timestamps
- Audit trail for all changes

## 📱 Responsive Design

- Works on desktop, tablet, and mobile
- Beautiful Tailwind CSS styling
- Font Awesome icons throughout
- Professional blue & teal color scheme

## 🚀 Technical Stack

- **Framework**: Laravel 11 (PHP)
- **Database**: MySQL
- **Frontend**: Blade Templates + Tailwind CSS
- **Icons**: Font Awesome 6.4.0
- **Authentication**: Laravel built-in auth

## 📝 Database Tables

- `users` - Admin accounts
- `job_orders` - Repair jobs with all metadata
- `status_histories` - Immutable audit log
- `technicians` - Staff management
- `repair_photos` - Before/after photos (ready for implementation)

## 🎁 Future Enhancements

Ready to implement when needed:
- Email/SMS customer notifications
- Photo uploads (before/after repair)
- PDF invoice generation
- Customer feedback/ratings
- Export reports (CSV/PDF)
- Multi-location support
- Advanced scheduling

## 💡 Tips for Maximum Benefit

1. **Set Priority Correctly**: Different turnaround times for different service levels
2. **Update Costs Immediately**: Record parts costs as they're used
3. **Add Detailed Notes**: Notes appear to customer - builds confidence
4. **Record Payments Promptly**: Keep balance accurate
5. **Mark Pickup**: Completes the job and tracks collection
6. **Check Dashboard Daily**: See business metrics at a glance

## 📞 Support

For questions or feature requests, contact the development team.

---

**Version**: 1.0.0  
**Last Updated**: December 27, 2025  
**Status**: Production Ready ✅

Built for modern electronics repair shop management
