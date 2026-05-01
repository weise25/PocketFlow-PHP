<?php

declare(strict_types=1);

function populateDatabase(string $dbFile): void
{
    $dbExists = file_exists($dbFile) && filesize($dbFile) > 0;

    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');

    if ($dbExists) {
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('customers', $tables)) {
            return;
        }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customers (
            customer_id INTEGER PRIMARY KEY AUTOINCREMENT,
            first_name TEXT NOT NULL,
            last_name TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL,
            registration_date DATE NOT NULL,
            city TEXT,
            country TEXT DEFAULT 'USA'
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS products (
            product_id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT,
            category TEXT NOT NULL,
            price REAL NOT NULL CHECK (price > 0),
            stock_quantity INTEGER NOT NULL DEFAULT 0 CHECK (stock_quantity >= 0)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS orders (
            order_id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER NOT NULL,
            order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status TEXT NOT NULL CHECK (status IN ('pending', 'processing', 'shipped', 'delivered', 'cancelled')),
            total_amount REAL,
            shipping_address TEXT,
            FOREIGN KEY (customer_id) REFERENCES customers (customer_id)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS order_items (
            order_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL CHECK (quantity > 0),
            price_per_unit REAL NOT NULL,
            FOREIGN KEY (order_id) REFERENCES orders (order_id),
            FOREIGN KEY (product_id) REFERENCES products (product_id)
        )
    ");

    $customers = [
        ['Alice', 'Smith', 'alice.s@email.com', '2023-01-15', 'New York', 'USA'],
        ['Bob', 'Johnson', 'b.johnson@email.com', '2023-02-20', 'Los Angeles', 'USA'],
        ['Charlie', 'Williams', 'charlie.w@email.com', '2023-03-10', 'Chicago', 'USA'],
        ['Diana', 'Brown', 'diana.b@email.com', '2023-04-05', 'Houston', 'USA'],
        ['Ethan', 'Davis', 'ethan.d@email.com', '2023-05-12', 'Phoenix', 'USA'],
        ['Fiona', 'Miller', 'fiona.m@email.com', '2023-06-18', 'Philadelphia', 'USA'],
        ['George', 'Wilson', 'george.w@email.com', '2023-07-22', 'San Antonio', 'USA'],
        ['Hannah', 'Moore', 'hannah.m@email.com', '2023-08-30', 'San Diego', 'USA'],
        ['Ian', 'Taylor', 'ian.t@email.com', '2023-09-05', 'Dallas', 'USA'],
        ['Julia', 'Anderson', 'julia.a@email.com', '2023-10-11', 'San Jose', 'USA'],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO customers (first_name, last_name, email, registration_date, city, country) VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($customers as $c) {
        $stmt->execute($c);
    }
    echo "Inserted " . count($customers) . " customers.\n";

    $products = [
        ['Laptop Pro', 'High-end laptop for professionals', 'Electronics', 1200.00, 50],
        ['Wireless Mouse', 'Ergonomic wireless mouse', 'Accessories', 25.50, 200],
        ['Mechanical Keyboard', 'RGB backlit mechanical keyboard', 'Accessories', 75.00, 150],
        ['4K Monitor', '27-inch 4K UHD Monitor', 'Electronics', 350.00, 80],
        ['Smartphone X', 'Latest generation smartphone', 'Electronics', 999.00, 120],
        ['Coffee Maker', 'Drip coffee maker', 'Home Goods', 50.00, 300],
        ['Running Shoes', 'Comfortable running shoes', 'Apparel', 90.00, 250],
        ['Yoga Mat', 'Eco-friendly yoga mat', 'Sports', 30.00, 400],
        ['Desk Lamp', 'Adjustable LED desk lamp', 'Home Goods', 45.00, 180],
        ['Backpack', 'Durable backpack for travel', 'Accessories', 60.00, 220],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO products (name, description, category, price, stock_quantity) VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($products as $p) {
        $stmt->execute($p);
    }
    echo "Inserted " . count($products) . " products.\n";

    $productPrices = $pdo->query('SELECT product_id, price FROM products')->fetchAll(PDO::FETCH_KEY_PAIR);
    $statuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];

    $pdo->beginTransaction();

    $orderStmt = $pdo->prepare(
        'INSERT INTO orders (customer_id, order_date, status, total_amount, shipping_address) VALUES (?, ?, ?, ?, ?)'
    );
    $itemStmt = $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, quantity, price_per_unit) VALUES (?, ?, ?, ?)'
    );
    $updateTotalStmt = $pdo->prepare(
        'UPDATE orders SET total_amount = ? WHERE order_id = ?'
    );

    $itemCount = 0;
    for ($orderId = 1; $orderId <= 20; $orderId++) {
        $customerId = random_int(1, 10);
        $daysAgo = random_int(0, 59);
        $orderDate = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));
        $status = $statuses[array_rand($statuses)];
        $shippingAddress = random_int(100, 999) . ' Main St, Anytown';

        $orderStmt->execute([$customerId, $orderDate, $status, 0, $shippingAddress]);

        $numItems = random_int(1, 4);
        $orderTotal = 0.0;
        for ($j = 0; $j < $numItems; $j++) {
            $productId = random_int(1, 10);
            $quantity = random_int(1, 5);
            $pricePerUnit = (float) $productPrices[$productId];
            $itemStmt->execute([$orderId, $productId, $quantity, $pricePerUnit]);
            $orderTotal += $quantity * $pricePerUnit;
            $itemCount++;
        }
        $updateTotalStmt->execute([round($orderTotal, 2), $orderId]);
    }

    $pdo->commit();
    echo "Inserted 20 orders.\n";
    echo "Inserted {$itemCount} order items.\n";
    echo "Database '{$dbFile}' created and populated successfully.\n";
}
