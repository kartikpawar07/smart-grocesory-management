<?php
/**
 * Grocery Budget Scheduling System - Main Application
 * 
 * Features:
 * - User Authentication (Login required)
 * - Smart Scheduling (adjusts quantity based on last month data)
 * - Greedy Algorithm (prioritizes essential items)
 * - Price-Proportional Quantity Adjustment (e.g., 1L ₹100 → 500ml ₹50)
 * - Add New Item functionality
 * - Budget enforcement (never exceeds entered budget)
 */

require_once 'config.php';

// Check if user is logged in
checkLogin();

// Get current user
$currentUser = getCurrentUser();

// Initialize variables
$budget = 0;
$userQuantities = [];
$optimizedResult = null;
$errorMessage = '';
$successMessage = '';

// Handle Add New Item
if (isset($_POST['add_item'])) {
    $itemName = trim($_POST['new_item_name'] ?? '');
    $itemCategory = trim($_POST['new_item_category'] ?? '');
    $itemPrice = intval($_POST['new_item_price'] ?? 0);
    $itemPriority = intval($_POST['new_item_priority'] ?? 2);
    
    if (empty($itemName) || empty($itemCategory) || $itemPrice <= 0) {
        $errorMessage = "Please fill all fields to add a new item.";
    } else {
        // Insert new item
        $stmt = $conn->prepare("INSERT INTO items (name, category, price, priority) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssii", $itemName, $itemCategory, $itemPrice, $itemPriority);
        
        if ($stmt->execute()) {
            $newItemId = $stmt->insert_id;
            // Add to monthly_data with default 0
            $stmt2 = $conn->prepare("INSERT INTO monthly_data (item_id, last_month_qty) VALUES (?, 0)");
            $stmt2->bind_param("i", $newItemId);
            $stmt2->execute();
            $stmt2->close();
            $successMessage = "New item '{$itemName}' added successfully!";
        } else {
            $errorMessage = "Failed to add item. It may already exist.";
        }
        $stmt->close();
    }
}

// Fetch all items from database with last month quantity
$items = [];
try {
    $sql = "SELECT i.*, m.last_month_qty 
            FROM items i 
            LEFT JOIN monthly_data m ON i.id = m.item_id 
            ORDER BY i.category, i.name";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
    }
} catch (Exception $e) {
    $errorMessage = "Error fetching items: " . $e->getMessage();
}

/**
 * SMART SCHEDULING MODULE - REDUCE ONLY
 * 
 * Rules:
 * 1. If last month was HIGH (>2) → REDUCE quantity (user had too much)
 * 2. If last month was LOW (≤1) → Keep user quantity (DON'T increase)
 * 3. NEVER increase user-selected quantity
 * 
 * @param int $userQty User's desired quantity
 * @param int $lastMonthQty Quantity purchased last month
 * @return array Adjusted quantity (only reduced, never increased)
 */
function applySmartScheduling($userQty, $lastMonthQty) {
    $scheduledQty = $userQty;
    $adjustmentReason = '';
    $wasAdjusted = false;
    
    if ($lastMonthQty > 2) {
        // Last month was HIGH - reduce quantity (user had excess)
        $scheduledQty = max(1, ceil($userQty / 2));
        $adjustmentReason = "Reduced from {$userQty} to {$scheduledQty} (last month was high: {$lastMonthQty})";
        $wasAdjusted = true;
    } else {
        // Keep user quantity as-is (don't increase even if last month was low)
        $adjustmentReason = "Kept at {$userQty} (user's selection)";
    }
    
    return [
        'original_qty' => $userQty,
        'scheduled_qty' => $scheduledQty,
        'reason' => $adjustmentReason,
        'was_adjusted' => $wasAdjusted
    ];
}

/**
 * PRICE-PROPORTIONAL QUANTITY ADJUSTMENT WITH PROTECTED ESSENTIALS
 * 
 * Rules:
 * 1. ESSENTIAL ITEMS (priority 1): NEVER REMOVE - always include with adjusted quantity
 * 2. OPTIONAL ITEMS (priority 2): Remove only if cannot afford even minimum quantity
 * 3. Process essential items FIRST, then optional items
 * 4. Show which optional items were removed and why
 * 
 * Example: Milk 1L @ ₹100 → 500ml @ ₹50 (essential - adjusted but kept)
 * Example: Chips ₹50, budget ₹10 → REMOVED (optional - cannot afford 0.25 = ₹12.50)
 * 
 * @param array $items List of items
 * @param int $budget Available budget
 * @return array Adjusted items, removed items with reasons
 */
function applyPriceProportionalReduction($items, $budget) {
    $suggestions = [];
    $adjustedItems = [];
    $removedItems = [];
    
    // Calculate initial total
    $totalCost = 0;
    foreach ($items as $item) {
        $totalCost += $item['price'] * $item['scheduled_qty'];
    }
    
    // If within budget, return all items as-is
    if ($totalCost <= $budget) {
        foreach ($items as $item) {
            $item['final_qty'] = $item['scheduled_qty'];
            $item['final_price'] = $item['price'] * $item['scheduled_qty'];
            $adjustedItems[] = $item;
        }
        return [
            'items' => $adjustedItems,
            'suggestions' => [],
            'total_cost' => $totalCost,
            'removed_items' => []
        ];
    }
    
    // Separate essential and optional items
    $essentialItems = [];
    $optionalItems = [];
    
    foreach ($items as $item) {
        if ($item['priority'] == 1) {
            $essentialItems[] = $item;
        } else {
            $optionalItems[] = $item;
        }
    }
    
    $remainingBudget = $budget;
    
    // ========== PHASE 1: Process ESSENTIAL ITEMS (NEVER REMOVE) ==========
    foreach ($essentialItems as $item) {
        $originalQty = $item['scheduled_qty'];
        $itemPrice = $item['price'];
        $itemTotal = $itemPrice * $originalQty;
        
        // If fits fully, add it
        if ($itemTotal <= $remainingBudget) {
            $item['final_qty'] = $originalQty;
            $item['final_price'] = $itemTotal;
            $adjustedItems[] = $item;
            $remainingBudget -= $itemTotal;
            continue;
        }
        
        // Need to reduce quantity - but MUST keep the item
        $affordableFraction = $remainingBudget / $itemPrice;
        
        if ($affordableFraction >= 0.5) {
            $newQty = floor($affordableFraction * 2) / 2;
            if ($newQty < 0.5) $newQty = 0.5;
        } elseif ($affordableFraction >= 0.25) {
            $newQty = 0.5;
        } elseif ($affordableFraction > 0) {
            // Can afford something less than 0.25 - use smallest possible
            $newQty = 0.25;
        } else {
            // Cannot afford anything - give warning but still add with minimum
            $newQty = 0.25;
        }
        
        $newTotal = $itemPrice * $newQty;
        
        $suggestions[] = [
            'item_name' => $item['name'],
            'original_qty' => $originalQty,
            'new_qty' => $newQty,
            'original_total' => $itemTotal,
            'new_total' => $newTotal,
            'message' => "{$item['name']} (Essential): {$originalQty} → {$newQty} (₹{$itemTotal} → ₹{$newTotal})"
        ];
        
        $item['final_qty'] = $newQty;
        $item['final_price'] = $newTotal;
        $item['was_reduced'] = true;
        $adjustedItems[] = $item;
        $remainingBudget -= $newTotal;
    }
    
    // ========== PHASE 2: Process OPTIONAL ITEMS (CAN REMOVE) ==========
    // Sort optional by price (low first) to save more items
    usort($optionalItems, function($a, $b) {
        return $a['price'] - $b['price'];
    });
    
    foreach ($optionalItems as $item) {
        $originalQty = $item['scheduled_qty'];
        $itemPrice = $item['price'];
        $itemTotal = $itemPrice * $originalQty;
        
        // If fits fully, add it
        if ($itemTotal <= $remainingBudget) {
            $item['final_qty'] = $originalQty;
            $item['final_price'] = $itemTotal;
            $adjustedItems[] = $item;
            $remainingBudget -= $itemTotal;
            continue;
        }
        
        // Need to reduce quantity
        $affordableFraction = $remainingBudget / $itemPrice;
        
        if ($affordableFraction >= 0.5) {
            $newQty = floor($affordableFraction * 2) / 2;
            if ($newQty < 0.5) $newQty = 0.5;
        } elseif ($affordableFraction >= 0.25) {
            $newQty = 0.5;
        } else {
            // Cannot afford even 0.25 - REMOVE this optional item
            $minCost = $itemPrice * 0.25;
            $removedItems[] = [
                'item' => $item,
                'reason' => "Removed: Cannot afford minimum quantity (0.25 = ₹{$minCost}). Budget remaining: ₹{$remainingBudget}"
            ];
            continue;
        }
        
        $newTotal = $itemPrice * $newQty;
        
        $suggestions[] = [
            'item_name' => $item['name'],
            'original_qty' => $originalQty,
            'new_qty' => $newQty,
            'original_total' => $itemTotal,
            'new_total' => $newTotal,
            'message' => "{$item['name']}: {$originalQty} → {$newQty} (₹{$itemTotal} → ₹{$newTotal})"
        ];
        
        $item['final_qty'] = $newQty;
        $item['final_price'] = $newTotal;
        $item['was_reduced'] = true;
        $adjustedItems[] = $item;
        $remainingBudget -= $newTotal;
    }
    
    // Re-sort to original order (by priority, essential first)
    usort($adjustedItems, function($a, $b) {
        if ($a['priority'] != $b['priority']) {
            return $a['priority'] - $b['priority'];
        }
        return strcmp($a['name'], $b['name']);
    });
    
    // Calculate final total
    $finalTotal = 0;
    foreach ($adjustedItems as $item) {
        $finalTotal += $item['final_price'];
    }
    
    return [
        'items' => $adjustedItems,
        'suggestions' => $suggestions,
        'total_cost' => $finalTotal,
        'removed_items' => $removedItems
    ];
}

/**
 * GREEDY ALGORITHM - Sort by priority
 */
function applyGreedySorting($items) {
    usort($items, function($a, $b) {
        if ($a['priority'] != $b['priority']) {
            return $a['priority'] - $b['priority'];
        }
        return $b['scheduled_qty'] - $a['scheduled_qty'];
    });
    return $items;
}

/**
 * MAIN OPTIMIZATION CONTROLLER
 */
function optimizeShoppingList($userSelections, $budget, $allItems) {
    $scheduledItems = [];
    $schedulingAdjustments = [];
    
    // Step 1: Apply Smart Scheduling
    foreach ($allItems as $item) {
        $itemId = $item['id'];
        if (isset($userSelections[$itemId])) {
            $userQty = floatval($userSelections[$itemId]);
            if ($userQty > 0) {
                $lastMonthQty = $item['last_month_qty'] ?? 0;
                $scheduling = applySmartScheduling($userQty, $lastMonthQty);
                
                $item['user_qty'] = $scheduling['original_qty'];
                $item['scheduled_qty'] = $scheduling['scheduled_qty'];
                $item['scheduling_reason'] = $scheduling['reason'];
                $item['was_scheduled'] = $scheduling['was_adjusted'];
                
                $scheduledItems[] = $item;
                
                if ($scheduling['was_adjusted']) {
                    $schedulingAdjustments[] = [
                        'item_name' => $item['name'],
                        'original_qty' => $scheduling['original_qty'],
                        'new_qty' => $scheduling['scheduled_qty'],
                        'reason' => $scheduling['reason']
                    ];
                }
            }
        }
    }
    
    // Step 2: Apply Price-Proportional Reduction to fit budget exactly
    $reductionResult = applyPriceProportionalReduction($scheduledItems, $budget);
    
    // Calculate summary
    $essentialCost = 0;
    $nonEssentialCost = 0;
    $essentialItems = [];
    $nonEssentialItems = [];
    
    foreach ($reductionResult['items'] as $item) {
        if ($item['priority'] == 1) {
            $essentialCost += $item['final_price'];
            $essentialItems[] = $item;
        } else {
            $nonEssentialCost += $item['final_price'];
            $nonEssentialItems[] = $item;
        }
    }
    
    return [
        'items' => $reductionResult['items'],
        'essential_items' => $essentialItems,
        'non_essential_items' => $nonEssentialItems,
        'scheduling_adjustments' => $schedulingAdjustments,
        'budget_suggestions' => $reductionResult['suggestions'],
        'removed_items' => $reductionResult['removed_items'],
        'total_budget' => $budget,
        'total_cost' => $reductionResult['total_cost'],
        'remaining_budget' => $budget - $reductionResult['total_cost'],
        'essential_cost' => $essentialCost,
        'non_essential_cost' => $nonEssentialCost,
        'item_count' => count($reductionResult['items'])
    ];
}

// Handle POST request for optimization
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['add_item'])) {
    $budget = isset($_POST['budget']) ? intval($_POST['budget']) : 0;
    $userQuantities = isset($_POST['quantities']) && is_array($_POST['quantities']) ? $_POST['quantities'] : [];
    
    $userSelections = [];
    foreach ($userQuantities as $itemId => $qty) {
        if (floatval($qty) > 0) {
            $userSelections[$itemId] = floatval($qty);
        }
    }
    
    if ($budget <= 0) {
        $errorMessage = "Please enter a valid budget greater than 0.";
    } elseif (empty($userSelections)) {
        $errorMessage = "Please select at least one item with quantity greater than 0.";
    } elseif ($budget > 100000) {
        $errorMessage = "Budget is too high.";
    } else {
        $optimizedResult = optimizeShoppingList($userSelections, $budget, $items);
        
        if (empty($optimizedResult['items'])) {
            $errorMessage = "No items could be selected within your budget.";
        } else {
            $successMessage = "Shopping list optimized! Total: ₹" . number_format($optimizedResult['total_cost']) . 
                            " (Budget: ₹" . number_format($budget) . ")";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grocery Budget Scheduling System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; padding-bottom: 50px; }
        .header-section {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 20px 0;
            margin-bottom: 30px;
        }
        .card { border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .card-header { background-color: #fff; border-bottom: 2px solid #28a745; font-weight: bold; }
        .essential-badge { background-color: #dc3545; }
        .non-essential-badge { background-color: #6c757d; }
        .result-table th { background-color: #28a745; color: white; }
        .summary-card { background-color: #e8f5e9; }
        .btn-optimize {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none; padding: 12px 40px; font-size: 1.1rem;
        }
        .category-section { margin-bottom: 20px; }
        .category-header {
            background-color: #f1f3f4; padding: 10px 15px;
            border-radius: 5px; margin-bottom: 10px;
            font-weight: bold; color: #495057;
        }
        .item-row {
            transition: all 0.3s ease; padding: 10px;
            border-radius: 5px; border-bottom: 1px solid #eee;
        }
        .item-row:hover { background-color: #f8f9fa; }
        .qty-input { width: 80px; }
        .suggestion-box {
            background-color: #fff3cd; border-left: 4px solid #ffc107;
            padding: 15px; margin-bottom: 15px;
        }
        .adjustment-box {
            background-color: #d1ecf1; border-left: 4px solid #17a2b8;
            padding: 15px; margin-bottom: 15px;
        }
        .removed-box {
            background-color: #f8d7da; border-left: 4px solid #dc3545;
            padding: 15px; margin-bottom: 15px; border-radius: 5px;
        }
        .user-info { text-align: right; padding: 10px 0; }
        .add-item-form {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .fraction-qty { color: #28a745; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1>Grocery Budget Scheduling System</h1>
                    <p class="mb-0">Smart Scheduling + Price-Proportional Quantity Adjustment</p>
                </div>
                <div class="col-md-4 user-info">
                    <span>Welcome, <strong><?php echo htmlspecialchars($currentUser['name']); ?></strong>!</span>
                    <a href="logout.php" class="btn btn-light btn-sm ms-3">Logout</a>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <?php if (!empty($errorMessage)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($errorMessage); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($successMessage)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($successMessage); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Left Column -->
            <div class="col-lg-6">
                <!-- Add New Item Form -->
                <div class="card">
                    <div class="card-header bg-info text-white">
                        Add New Item
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <div class="row">
                                <div class="col-md-4 mb-2">
                                    <input type="text" class="form-control" name="new_item_name" 
                                           placeholder="Item Name" required>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <input type="text" class="form-control" name="new_item_category" 
                                           placeholder="Category" required>
                                </div>
                                <div class="col-md-2 mb-2">
                                    <input type="number" class="form-control" name="new_item_price" 
                                           placeholder="Price" min="1" required>
                                </div>
                                <div class="col-md-2 mb-2">
                                    <select class="form-select" name="new_item_priority" required>
                                        <option value="1">Essential</option>
                                        <option value="2" selected>Optional</option>
                                    </select>
                                </div>
                                <div class="col-md-1 mb-2">
                                    <button type="submit" name="add_item" class="btn btn-success">+</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Budget & Item Selection -->
                <div class="card">
                    <div class="card-header">Enter Budget & Select Items</div>
                    <div class="card-body">
                        <form method="POST" action="" id="groceryForm">
                            <div class="mb-4">
                                <label for="budget" class="form-label"><strong>Monthly Budget (₹)</strong></label>
                                <input type="number" class="form-control form-control-lg" id="budget" 
                                       name="budget" min="1" max="100000"
                                       placeholder="Enter your budget"
                                       value="<?php echo isset($_POST['budget']) ? htmlspecialchars($_POST['budget']) : ''; ?>"
                                       required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label"><strong>Select Items & Enter Quantity</strong></label>
                                <div class="form-text mb-3">
                                    <span class="badge essential-badge">Essential</span> = Must have | 
                                    <span class="badge non-essential-badge">Optional</span> = Good to have
                                </div>

                                <?php if (empty($items)): ?>
                                    <div class="alert alert-warning">No items available.</div>
                                <?php else: ?>
                                    <?php
                                    $groupedItems = [];
                                    foreach ($items as $item) {
                                        $groupedItems[$item['category']][] = $item;
                                    }
                                    
                                    foreach ($groupedItems as $category => $categoryItems):
                                    ?>
                                        <div class="category-section">
                                            <div class="category-header"><?php echo htmlspecialchars($category); ?></div>
                                            <?php 
                                            $displayedNames = []; // Track displayed names to avoid duplicates
                                            foreach ($categoryItems as $item): 
                                                // Skip if this exact name already displayed
                                                if (in_array($item['name'], $displayedNames)) continue;
                                                $displayedNames[] = $item['name'];
                                                
                                                $qty = isset($_POST['quantities'][$item['id']]) ? $_POST['quantities'][$item['id']] : '';
                                            ?>
                                                <div class="item-row d-flex justify-content-between align-items-center mb-2">
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                                        <?php if ($item['priority'] == 1): ?>
                                                            <span class="badge essential-badge">Essential</span>
                                                        <?php else: ?>
                                                            <span class="badge non-essential-badge">Optional</span>
                                                        <?php endif; ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            ₹<?php echo $item['price']; ?>/unit | 
                                                            Last month: <?php echo $item['last_month_qty'] ?? 0; ?> qty
                                                        </small>
                                                    </div>
                                                    <div>
                                                        <input type="number" class="form-control qty-input" 
                                                               name="quantities[<?php echo $item['id']; ?>]" 
                                                               min="0" max="20" step="0.5" placeholder="0"
                                                               value="<?php echo $qty; ?>">
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <div class="text-center mt-4">
                                <button type="submit" class="btn btn-primary btn-optimize">Optimize Shopping List</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Right Column: Results -->
            <div class="col-lg-6">
                <?php if ($optimizedResult): ?>
                    
                    <!-- Summary Card -->
                    <div class="card summary-card mb-4">
                        <div class="card-header bg-success text-white">Budget Summary</div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-3">
                                    <h6 class="text-primary">₹<?php echo number_format($optimizedResult['total_budget']); ?></h6>
                                    <small>Budget</small>
                                </div>
                                <div class="col-3">
                                    <h6 class="text-success">₹<?php echo number_format($optimizedResult['total_cost']); ?></h6>
                                    <small>Used</small>
                                </div>
                                <div class="col-3">
                                    <h6 class="text-info">₹<?php echo number_format($optimizedResult['remaining_budget']); ?></h6>
                                    <small>Remaining</small>
                                </div>
                                <div class="col-3">
                                    <h6 class="text-dark"><?php echo $optimizedResult['item_count']; ?></h6>
                                    <small>Items</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Scheduling Adjustments -->
                    <?php if (!empty($optimizedResult['scheduling_adjustments'])): ?>
                        <div class="card">
                            <div class="card-header bg-info text-white">Smart Scheduling Adjustments</div>
                            <div class="card-body">
                                <?php foreach ($optimizedResult['scheduling_adjustments'] as $adj): ?>
                                    <div class="adjustment-box">
                                        <strong><?php echo htmlspecialchars($adj['item_name']); ?></strong>: 
                                        <?php echo htmlspecialchars($adj['reason']); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Budget Adjustments -->
                    <?php if (!empty($optimizedResult['budget_suggestions'])): ?>
                        <div class="card">
                            <div class="card-header bg-warning text-dark">Price-Proportional Adjustments</div>
                            <div class="card-body">
                                <p class="small text-muted mb-2">Quantities adjusted to fit your budget:</p>
                                <?php foreach ($optimizedResult['budget_suggestions'] as $sugg): ?>
                                    <div class="suggestion-box">
                                        <strong><?php echo htmlspecialchars($sugg['message']); ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Removed Items -->
                    <?php if (!empty($optimizedResult['removed_items'])): ?>
                        <div class="card">
                            <div class="card-header bg-danger text-white">Items Removed</div>
                            <div class="card-body">
                                <p class="small text-muted mb-2">These items could not fit even with minimum quantity:</p>
                                <?php foreach ($optimizedResult['removed_items'] as $removed): ?>
                                    <div class="removed-box">
                                        <strong><?php echo htmlspecialchars($removed['item']['name']); ?></strong>
                                        <?php if ($removed['item']['priority'] == 1): ?>
                                            <span class="badge essential-badge">Essential</span>
                                        <?php else: ?>
                                            <span class="badge non-essential-badge">Optional</span>
                                        <?php endif; ?>
                                        <br>
                                        <small><?php echo htmlspecialchars($removed['reason']); ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Final Shopping List -->
                    <div class="card">
                        <div class="card-header bg-primary text-white">Your Optimized Shopping List</div>
                        <div class="card-body p-0">
                            <table class="table table-striped mb-0 result-table">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-end">Price</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $displayedItems = []; // Track unique items in results
                                    foreach ($optimizedResult['items'] as $item): 
                                        // Skip duplicates
                                        $itemKey = $item['name'] . '_' . $item['final_qty'];
                                        if (in_array($itemKey, $displayedItems)) continue;
                                        $displayedItems[] = $itemKey;
                                    ?>
                                        <tr>
                                            <td>
                                                <?php echo htmlspecialchars($item['name']); ?>
                                                <?php if ($item['priority'] == 1): ?>
                                                    <span class="badge essential-badge">E</span>
                                                <?php else: ?>
                                                    <span class="badge non-essential-badge">N</span>
                                                <?php endif; ?>
                                                <?php if (isset($item['was_reduced']) && $item['was_reduced']): ?>
                                                    <span class="badge bg-warning text-dark">Adjusted</span>
                                                <?php endif; ?>
                                                <?php if (isset($item['was_scheduled']) && $item['was_scheduled']): ?>
                                                    <span class="badge bg-info">Scheduled</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center fraction-qty">
                                                <?php 
                                                // Display as fraction if needed
                                                $qty = $item['final_qty'];
                                                if ($qty == 0.5) echo '1/2';
                                                elseif ($qty == 0.25) echo '1/4';
                                                elseif ($qty == floor($qty)) echo intval($qty);
                                                else echo $qty;
                                                ?>
                                                <?php if (isset($item['user_qty']) && $item['user_qty'] != $item['scheduled_qty']): ?>
                                                    <br><small class="text-muted">(wanted <?php echo $item['user_qty']; ?>)</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">₹<?php echo number_format($item['final_price'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-group-divider">
                                    <tr class="table-success">
                                        <td colspan="2"><strong>Grand Total</strong></td>
                                        <td class="text-end"><strong>₹<?php echo number_format($optimizedResult['total_cost'], 2); ?></strong></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- Algorithm Explanation -->
                    <div class="card bg-light">
                        <div class="card-header">How Your List Was Optimized</div>
                        <div class="card-body">
                            <ol>
                                <li><strong>Smart Scheduling:</strong> Quantities adjusted based on last month's data</li>
                                <li><strong>Greedy Sorting:</strong> Essential items prioritized</li>
                                <li><strong>Price-Proportional Reduction:</strong> Quantities reduced proportionally to fit budget exactly
                                    <br><small class="text-muted">Example: Milk 1L ₹100 → 500ml ₹50</small>
                                </li>
                            </ol>
                            <p class="small text-muted mb-0">
                                <strong>Result:</strong> Total (₹<?php echo number_format($optimizedResult['total_cost']); ?>)
                                ≤ Budget (₹<?php echo number_format($optimizedResult['total_budget']); ?>)
                            </p>
                        </div>
                    </div>

                <?php else: ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <h5 class="text-muted">Your optimized shopping list will appear here</h5>
                            <p class="text-muted">Enter your budget, select items with quantities, and click "Optimize Shopping List"</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('groceryForm').addEventListener('submit', function(e) {
            const budget = document.getElementById('budget').value;
            const quantities = document.querySelectorAll('input[name^="quantities["]');
            let hasSelection = false;
            
            quantities.forEach(function(input) {
                if (parseFloat(input.value) > 0) {
                    hasSelection = true;
                }
            });
            
            if (budget <= 0) {
                alert('Please enter a valid budget greater than 0.');
                e.preventDefault();
                return false;
            }
            
            if (!hasSelection) {
                alert('Please enter quantity for at least one item.');
                e.preventDefault();
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>
