-- Grocery Budget Scheduling System - Database Schema

-- Create database
CREATE DATABASE IF NOT EXISTS grocery_budget_db;
USE grocery_budget_db;

-- Table: users
-- Stores registered users for login system
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table: items
-- Stores grocery items with their details and priority
CREATE TABLE IF NOT EXISTS items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    category VARCHAR(50) NOT NULL,
    price INT NOT NULL,
    priority INT NOT NULL COMMENT '1 = Essential, 2 = Others'
);

-- Table: monthly_data
-- Stores last month's purchase quantity for scheduling logic
CREATE TABLE IF NOT EXISTS monthly_data (
    item_id INT PRIMARY KEY,
    last_month_qty INT NOT NULL DEFAULT 0,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);

-- Insert sample grocery items
INSERT INTO items (name, category, price, priority) VALUES
-- Essential Items (Priority = 1)
('Rice (1kg)', 'Staples', 50, 1),
('Wheat Flour (1kg)', 'Staples', 40, 1),
('Sugar (1kg)', 'Staples', 45, 1),
('Salt (1kg)', 'Staples', 20, 1),
('Cooking Oil (1L)', 'Staples', 120, 1),
('Milk (1L)', 'Dairy', 60, 1),
('Eggs (12 pcs)', 'Dairy', 80, 1),
('Onions (1kg)', 'Vegetables', 30, 1),
('Potatoes (1kg)', 'Vegetables', 25, 1),
('Tomatoes (1kg)', 'Vegetables', 40, 1),

-- Non-Essential Items (Priority = 2)
('Apples (1kg)', 'Fruits', 150, 2),
('Bananas (1 dozen)', 'Fruits', 60, 2),
('Chicken (1kg)', 'Meat', 250, 2),
('Fish (1kg)', 'Meat', 300, 2),
('Biscuits (Pack)', 'Snacks', 30, 2),
('Chips (Pack)', 'Snacks', 20, 2),
('Chocolate', 'Snacks', 50, 2),
('Soft Drink (1L)', 'Beverages', 40, 2),
('Tea (250g)', 'Beverages', 80, 2),
('Coffee (100g)', 'Beverages', 120, 2),
('Detergent (1kg)', 'Household', 150, 2),
('Soap (Pack)', 'Household', 45, 2),
('Shampoo (200ml)', 'Personal Care', 120, 2),
('Toothpaste', 'Personal Care', 60, 2);

-- Insert last month purchase data for scheduling logic
-- Using INSERT IGNORE to skip if data already exists
INSERT IGNORE INTO monthly_data (item_id, last_month_qty) VALUES
-- Essential items with varying last month quantities
(1, 2),   -- Rice: bought 2 last month
(2, 1),   -- Wheat Flour: bought 1 last month
(3, 3),   -- Sugar: bought 3 last month
(4, 1),   -- Salt: bought 1 last month
(5, 2),   -- Cooking Oil: bought 2 last month
(6, 4),   -- Milk: bought 4 last month
(7, 2),   -- Eggs: bought 2 last month
(8, 3),   -- Onions: bought 3 last month
(9, 2),   -- Potatoes: bought 2 last month
(10, 1),  -- Tomatoes: bought 1 last month

-- Non-essential items with varying last month quantities
(11, 2),  -- Apples: bought 2 last month
(12, 3),  -- Bananas: bought 3 last month
(13, 1),  -- Chicken: bought 1 last month
(14, 0),  -- Fish: bought 0 last month
(15, 4),  -- Biscuits: bought 4 last month
(16, 2),  -- Chips: bought 2 last month
(17, 1),  -- Chocolate: bought 1 last month
(18, 3),  -- Soft Drink: bought 3 last month
(19, 2),  -- Tea: bought 2 last month
(20, 1),  -- Coffee: bought 1 last month
(21, 1),  -- Detergent: bought 1 last month
(22, 2),  -- Soap: bought 2 last month
(23, 0),  -- Shampoo: bought 0 last month
(24, 1);  -- Toothpaste: bought 1 last month
