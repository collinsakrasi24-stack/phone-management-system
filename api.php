<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Database configuration
$host = 'localhost';
$dbname = 'phone_shop';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Connection failed: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? '';

switch($action) {
    case 'stats':
        getStats($pdo);
        break;
    
    case 'products':
        getProducts($pdo);
        break;
    
    case 'customers':
        getCustomers($pdo);
        break;
    
    case 'sales':
        getSales($pdo);
        break;
    
    case 'add_product':
        addProduct($pdo);
        break;
    
    case 'add_customer':
        addCustomer($pdo);
        break;
    
    case 'add_sale':
        addSale($pdo);
        break;
    
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function getStats($pdo) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM products");
        $totalProducts = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM products WHERE stock > 0 AND stock <= 20");
        $lowStock = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM customers");
        $totalCustomers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM sales");
        $totalSales = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
        
        $stmt = $pdo->query("SELECT SUM(total_amount) as total FROM sales");
        $totalRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
        
        echo json_encode([
            'products' => $totalProducts,
            'low_stock' => $lowStock,
            'customers' => $totalCustomers,
            'sales' => $totalSales,
            'revenue' => $totalRevenue
        ]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function getProducts($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM products ORDER BY id DESC");
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($products);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function getCustomers($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM customers ORDER BY id DESC");
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($customers);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function getSales($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT 
                s.sale_id,
                s.sale_date,
                s.total_amount,
                s.payment_method,
                c.name as customer_name,
                c.phone as customer_phone,
                GROUP_CONCAT(CONCAT(p.name, ' (x', sd.quantity, ')') SEPARATOR ', ') as products
            FROM sales s
            JOIN customers c ON s.customer_id = c.id
            LEFT JOIN salesdetails sd ON s.sale_id = sd.sale_id
            LEFT JOIN products p ON sd.product_id = p.id
            GROUP BY s.sale_id, s.sale_date, s.total_amount, s.payment_method, c.name, c.phone
            ORDER BY s.sale_date DESC, s.sale_id DESC
        ");
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($sales);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function addProduct($pdo) {
    try {
        $name = $_POST['name'] ?? '';
        $brand = $_POST['brand'] ?? '';
        $category = $_POST['category'] ?? '';
        $price = $_POST['price'] ?? 0;
        $stock = $_POST['stock'] ?? 0;
        
        if(empty($name) || empty($brand) || empty($category)) {
            echo json_encode(['success' => false, 'message' => 'All fields are required']);
            return;
        }
        
        $stmt = $pdo->prepare("INSERT INTO products (name, brand, category, price, stock) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $brand, $category, $price, $stock]);
        
        echo json_encode(['success' => true, 'message' => 'Product added successfully', 'id' => $pdo->lastInsertId()]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function addCustomer($pdo) {
    try {
        $name = $_POST['name'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $email = $_POST['email'] ?? '';
        
        if(empty($name) || empty($phone) || empty($email)) {
            echo json_encode(['success' => false, 'message' => 'All fields are required']);
            return;
        }
        
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM customers WHERE email = ?");
        $stmt->execute([$email]);
        if($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email already exists']);
            return;
        }
        
        $stmt = $pdo->prepare("INSERT INTO customers (name, phone, email) VALUES (?, ?, ?)");
        $stmt->execute([$name, $phone, $email]);
        
        echo json_encode(['success' => true, 'message' => 'Customer added successfully', 'id' => $pdo->lastInsertId()]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function addSale($pdo) {
    try {
        $customer_id = $_POST['customer_id'] ?? 0;
        $product_id = $_POST['product_id'] ?? 0;
        $quantity = $_POST['quantity'] ?? 0;
        $sale_date = $_POST['sale_date'] ?? date('Y-m-d');
        $payment_method = $_POST['payment_method'] ?? 'Cash';
        
        if(empty($customer_id) || empty($product_id) || empty($quantity)) {
            echo json_encode(['success' => false, 'message' => 'All fields are required']);
            return;
        }
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Get product details and check stock
        $stmt = $pdo->prepare("SELECT name, price, stock FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if(!$product) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Product not found']);
            return;
        }
        
        if($product['stock'] < $quantity) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Insufficient stock. Available: ' . $product['stock']]);
            return;
        }
        
        // Calculate totals
        $sale_price = $product['price'];
        $subtotal = $sale_price * $quantity;
        $total_amount = $subtotal;
        
        // Insert sale
        $stmt = $pdo->prepare("INSERT INTO sales (customer_id, sale_date, total_amount, payment_method) VALUES (?, ?, ?, ?)");
        $stmt->execute([$customer_id, $sale_date, $total_amount, $payment_method]);
        $sale_id = $pdo->lastInsertId();
        
        // Insert sale details
        $stmt = $pdo->prepare("INSERT INTO salesdetails (sale_id, product_id, quantity, sale_price, subtotal) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$sale_id, $product_id, $quantity, $sale_price, $subtotal]);
        
        // Update product stock
        $stmt = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
        $stmt->execute([$quantity, $product_id]);
        
        // Commit transaction
        $pdo->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Sale recorded successfully!',
            'sale_id' => $sale_id,
            'total' => $total_amount
        ]);
        
    } catch(PDOException $e) {
        if($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}
?>