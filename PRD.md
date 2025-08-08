# 📄 Dune Awakening Guild Resource Tracker

## 🧭 Project Overview

A lightweight PHP + MySQL web app for Dune: Awakening guild members to log Deep Desert resources, view total guild inventory, and track contributions. Access is restricted via Discord OAuth login.

---

## ⚙️ Core Features

### ✅ Discord OAuth Login
- Authenticate users via Discord
- Store Discord ID, username, avatar
- Use sessions to maintain login
- Optional: verify user is in specific guild

### 📥 Resource Contribution Form
- Resource type dropdown
- Quantity input
- Optional note field
- Saves to contributions table linked to user

### 📊 Dashboard
- View all resources and totals
- Filter by user, type, date
- Contribution history table

### 👤 My Contributions
- Logged-in user's full submission history
- Optional export to CSV

### 🔐 Admin Panel (Optional Phase 2)
- View/edit all contributions
- Manage distributions
- Filter invalid/duplicate entries

---

## 🧱 Tech Stack

- **Frontend**: HTML/CSS (Bootstrap optional)
- **Backend**: PHP
- **Database**: MySQL
- **Auth**: Discord OAuth2
- **Hosting**: GoDaddy shared hosting

---

## 📁 Pages & Files

| File / Page           | Purpose                                  |
|-----------------------|------------------------------------------|
| `login.php`           | Redirects user to Discord login          |
| `callback.php`        | Handles token exchange, saves session    |
| `index.php`           | Dashboard with total inventory           |
| `submit.php`          | Form to log resource contributions       |
| `my-contributions.php`| View logged-in user’s history            |
| `admin.php`           | Admin-only panel (optional)              |
| `logout.php`          | Ends session                             |
| `includes/db.php`     | MySQL connection file                    |
| `includes/auth.php`   | Session validator for protected pages    |
| `config.php`          | Holds constants for Discord OAuth        |

---

## 🗃️ MySQL Schema

```sql
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  discord_id VARCHAR(50) UNIQUE,
  username VARCHAR(100),
  avatar TEXT
);

CREATE TABLE resources (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100),
  category VARCHAR(100),
  description TEXT
);

CREATE TABLE contributions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  resource_id INT NOT NULL,
  quantity INT NOT NULL,
  date_collected DATETIME DEFAULT CURRENT_TIMESTAMP,
  notes TEXT,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (resource_id) REFERENCES resources(id)
);

CREATE TABLE distributions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  resource_id INT NOT NULL,
  quantity INT NOT NULL,
  date_given DATETIME DEFAULT CURRENT_TIMESTAMP,
  notes TEXT,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (resource_id) REFERENCES resources(id)
);
