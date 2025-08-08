# Testing Guide for Farming Run System

## 1. Database Setup

First, make sure your local database has all the migrations:

```bash
# Login to MySQL
mysql -u root -p

# Select your database
USE dune_tracker;

# Run the farming runs migration
SOURCE /Users/charlieanderson/Code/Rubi-Ka/database/migrations/002_add_farming_runs.sql;

# Verify tables were created
SHOW TABLES;
# Should see: farming_runs, run_participants, run_collections, run_refined_outputs
```

## 2. Start Local Server

```bash
cd /Users/charlieanderson/Code/Rubi-Ka/public_html
php -S localhost:8000
```

## 3. Testing Flow

### Step 1: Initial Setup
1. Open http://localhost:8000
2. Click "Login with Discord"
3. Authorize the app
4. You should be logged in and see the dashboard

### Step 2: Create Test Users (Optional)
If you want to test with multiple users without multiple Discord accounts:

```sql
-- Add some test users to MySQL
INSERT INTO users (discord_id, username, avatar) VALUES 
('test_user_1', 'TestPlayer1', null),
('test_user_2', 'TestPlayer2', null),
('test_user_3', 'TestPlayer3', null);
```

### Step 3: Test Farming Runs

1. **Create a Run**
   - Click "Farming Runs" in the dashboard
   - Click "Start New Farming Run"
   - Name: "Test Spice Run #1"
   - Type: "Spice Farming"
   - Location: "Northern Deep Desert"
   - Click "Start Farming Run"

2. **Add Participants (as Leader)**
   - You'll be redirected to the run page
   - Click the "Manage" tab
   - Select guild members from dropdown
   - Click "Add to Run"
   - Verify they appear in the participants list

3. **Log Collections**
   - Click "Log Collection" tab
   - Select "Raw Spice" (or any resource)
   - Enter quantity: 100
   - Add notes: "First haul"
   - Click "Add Collection"

4. **Log Refinement**
   - Click "Log Refinement" tab
   - Input: Raw Spice, Quantity: 100
   - Output: Melange (Spice), Quantity: 85
   - Click "Record Refinement"

5. **View Contributions**
   - Click "Contributions" tab
   - Should see individual totals
   - Click "Summary" tab for group totals

6. **Complete the Run**
   - Click "Complete Run" button
   - Run status changes to "completed"

## 4. Test Scenarios

### Scenario A: Multi-User Run
1. Create a run as User A (leader)
2. Add Users B and C via Manage tab
3. Log in as User B (use incognito/different browser)
4. User B logs some collections
5. Log back as User A
6. View Contributions tab - should see both users' data

### Scenario B: Remove Participant
1. As leader, go to Manage tab
2. Click "Remove" next to a participant
3. Confirm removal
4. Check they're removed from participants list

### Scenario C: Join vs Assign
1. Create a run as leader
2. Have another user access the run URL
3. They should see "Join Run" button
4. Alternatively, assign them via Manage tab
5. Both methods should work

## 5. Common Issues & Solutions

### "No Discord users showing in dropdown"
- Make sure users have logged in at least once
- Check the `users` table has entries

### "Can't see Manage tab"
- Only the run leader (creator) can see this tab
- Check you're logged in as the leader

### "Collections not showing"
- Refresh the page after adding
- Check browser console for errors
- Verify database connection

## 6. Database Checks

```sql
-- Check active runs
SELECT * FROM farming_runs WHERE status = 'active';

-- Check participants
SELECT * FROM run_participants WHERE run_id = 1;

-- Check collections
SELECT * FROM run_collections WHERE run_id = 1;

-- Check user contributions
SELECT 
    u.username,
    COUNT(rc.id) as collections,
    SUM(rc.quantity) as total
FROM run_participants rp
JOIN users u ON rp.user_id = u.id
LEFT JOIN run_collections rc ON rc.run_id = rp.run_id AND rc.collected_by = u.id
WHERE rp.run_id = 1
GROUP BY u.id;
```

## 7. Quick Test Data

If you want to quickly populate a run with test data:

```sql
-- Assuming run_id = 1 and you have some users
INSERT INTO run_collections (run_id, resource_id, quantity, collected_by, notes) VALUES
(1, 1, 150, 1, 'First collection'),
(1, 2, 75, 2, 'Found water cache'),
(1, 16, 200, 1, 'Big spice field');

INSERT INTO run_refined_outputs (run_id, input_resource_id, output_resource_id, input_quantity, output_quantity, conversion_rate, refined_by) VALUES
(1, 16, 1, 200, 170, 0.85, 1);
```